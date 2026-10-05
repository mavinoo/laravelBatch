<?php declare(strict_types=1);

namespace Mavinoo\Batch;

use Illuminate\Support\Facades\Facade;

/**
 * @method static int update(\Illuminate\Database\Eloquent\Model $table, array<array-key, mixed> $values, ?string $index = null)
 * @method static int updateWithTwoIndex(\Illuminate\Database\Eloquent\Model $table, array<array-key, mixed> $values, ?string $index = null, ?string $index2 = null)
 * @method static int updateMultipleCondition(\Illuminate\Database\Eloquent\Model $table, array<array-key, mixed> $values, ?string $index = null)
 * @method static array{totalRows: int, totalBatch: int, totalQuery: int} insert(\Illuminate\Database\Eloquent\Model $table, array<int, string> $columns, array<array-key, mixed> $values, int $batchSize = 500, bool $insertIgnore = false)
 * @method static int updateByKeys(\Illuminate\Database\Eloquent\Model $table, array<array-key, mixed> $values, array<array-key, mixed> $keys)
 * @method static array{totalRows: int, totalBatch: int, totalQuery: int} insertRows(\Illuminate\Database\Eloquent\Model $table, array<array-key, mixed> $rows, int $batchSize = 500, bool $insertIgnore = false)
 * @method static list<int> insertGetIds(\Illuminate\Database\Eloquent\Model $table, array<array-key, mixed> $rows, int $batchSize = 500)
 * @method static array{totalRows: int, skipped: int} import(\Illuminate\Database\Eloquent\Model $table, mixed $source, array<string, mixed> $options = [])
 * @method static list<string> splitFile(string $path, ?int $lines = null, ?int $bytes = null, array<string, mixed> $options = [])
 * @method static int deleteInChunks(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder $query, int $chunk = 1000, int $sleepMs = 0, bool $force = false, ?callable $onChunk = null)
 * @method static int updateInChunks(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder $query, array<array-key, mixed> $values, int $chunk = 1000, int $sleepMs = 0, ?callable $onChunk = null)
 * @method static int archive(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder $query, \Illuminate\Database\Eloquent\Model|string $target, int $chunk = 1000, int $sleepMs = 0, bool $delete = true, ?list<string> $columns = null, ?callable $onChunk = null)
 * @method static int deleteByKeys(\Illuminate\Database\Eloquent\Model $table, array<array-key, mixed> $values, array<array-key, mixed> $keys, bool $force = false)
 * @method static int upsert(\Illuminate\Database\Eloquent\Model $table, array<array-key, mixed> $values, array<int, string>|string $uniqueBy, ?array<array-key, mixed> $update = null)
 * @method static list<array{sql: string, bindings: array<mixed>, connection: string}> pretend(callable $callback)
 *
 * @see \Mavinoo\Batch\Batch
 */
class BatchFacade extends Facade
{
    /**
     * Get facade accessor to retrieve instance.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'Batch';
    }
}
