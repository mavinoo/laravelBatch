<?php declare(strict_types=1);

namespace Mavinoo\Batch;

use Illuminate\Database\Eloquent\Model;

interface BatchInterface
{
    /**
     * Update many rows, matched on one column, in a single query.
     *
     * @param Model $table
     * @param array $values
     * @param string|null $index
     * @return int number of affected rows
     */
    public function update(Model $table, array $values, ?string $index = null): int;

    /**
     * Update many rows, matched on two columns, in a single query.
     *
     * @param Model $table
     * @param array $values
     * @param string|null $index
     * @param string|null $index2
     * @return int number of affected rows
     */
    public function updateWithTwoIndex(Model $table, array $values, ?string $index = null, ?string $index2 = null): int;

    /**
     * Update many rows, each matched on its own set of conditions, in a single query.
     *
     * @param Model $table
     * @param array $values
     * @param string|null $index
     * @return int number of affected rows
     */
    public function updateMultipleCondition(Model $table, array $values, ?string $index = null): int;

    /**
     * Insert many rows, $batchSize rows per query, in one transaction.
     *
     * @param Model $table
     * @param array $columns
     * @param array $values
     * @param int $batchSize
     * @param bool $insertIgnore
     * @return array{totalRows: int, totalBatch: int, totalQuery: int}
     */
    public function insert(Model $table, array $columns, array $values, int $batchSize = 500, bool $insertIgnore = false): array;
}
