<?php declare(strict_types=1);

namespace Mavinoo\Batch;

use Illuminate\Support\Facades\Facade;

/**
 * @method static int update(\Illuminate\Database\Eloquent\Model $table, array $values, ?string $index = null)
 * @method static int updateWithTwoIndex(\Illuminate\Database\Eloquent\Model $table, array $values, ?string $index = null, ?string $index2 = null)
 * @method static int updateMultipleCondition(\Illuminate\Database\Eloquent\Model $table, array $values, ?string $index = null)
 * @method static array insert(\Illuminate\Database\Eloquent\Model $table, array $columns, array $values, int $batchSize = 500, bool $insertIgnore = false)
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
