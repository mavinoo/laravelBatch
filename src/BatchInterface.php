<?php declare(strict_types=1);

namespace Mavinoo\Batch;

use Illuminate\Database\Eloquent\Model;

interface BatchInterface
{
    /**
     * Update multiple rows.
     *
     * @param Model $table
     * @param array $values
     * @param string|null $index
     * @param bool $raw
     * @return mixed
     */
    public function update(Model $table, array $values, string $index = null, bool $raw = false);

    /**
     * Update multiple rows with two index.
     *
     * @param Model $table
     * @param array $values
     * @param string|null $index
     * @param string|null $index2
     * @param bool $raw
     * @return mixed
     */
    public function updateWithTwoIndex(Model $table, array $values, string $index = null, string $index2 = null, bool $raw = false);

    /**
     * Insert multiple rows.
     *
     * @param Model $table
     * @param array $columns
     * @param array $values
     * @param int $batchSize
     * @param bool $insertIgnore
     * @return mixed
     */
    public function insert(Model $table, array $columns, array $values, int $batchSize = 500, bool $insertIgnore = false);
}
