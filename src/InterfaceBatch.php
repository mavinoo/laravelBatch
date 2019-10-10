<?php

namespace Mavinoo\LaravelBatch;

use Illuminate\Database\Eloquent\Model;

interface InterfaceBatch
{
    public function update(Model $table, array $values, string $index = null, bool $raw = false);

    public function insert(Model $table, array $columns, array $values, int $batchSize = 500);
}
