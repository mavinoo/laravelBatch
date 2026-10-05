<?php

declare(strict_types=1);

namespace Mavinoo\Batch\Traits;

use Illuminate\Database\Eloquent\Model;
use Mavinoo\Batch\Batch;

/**
 * @phpstan-require-extends Model
 */
trait HasBatch
{
    /**
     * Update multiple rows.
     *
     * Example:
     * ```
     * use App\Models\User;
     *
     * $values = [
     *     [
     *         'id' => 1,
     *         'status' => 'active',
     *         'nickname' => 'Mohammad',
     *     ],
     *     [
     *         'id' => 5,
     *         'status' => 'deactive',
     *         'nickname' => 'Ghanbari',
     *     ],
     *     [
     *         'id' => 7,
     *         'balance' => ['+', 500],
     *     ],
     * ];
     *
     * User::batchUpdate($values, 'id');
     * ```
     *
     * @param  array<array-key, mixed>  $values
     * @param  string|null  $index
     *
     * @return int number of affected rows
     */
    public static function batchUpdate(array $values, ?string $index = null): int
    {
        // Extra arguments are passed on so a leftover v2 $raw flag fails loudly.
        return app(Batch::class)->update(self::batchModel(), $values, $index, ...array_slice(func_get_args(), 2));
    }

    /**
     * Update multiple condition rows
     *
     * @param  array<array-key, mixed>  $arrays
     * @param  string|null  $keyName
     *
     * @return int number of affected rows
     * @createdBy Mohammad Ghanbari <mavin.developer@gmail.com>
     * @deprecated since 3.1, use the static batchUpdateMultipleCondition(). Will be removed in 4.0.
     *
     * @desc
     * Example
     * $table = new \App\Models\User;
     * $arrays = [
     *      [
     *          'conditions' => ['id' => 1, 'status' => 'active'],
     *          'columns'    => [
     *              'status' => 'invalid'
     *              'nickname' => 'mohammad'
     *          ],
     *      ],
     *      [
     *          'conditions' => ['id' => 2],
     *          'columns'    => [
     *              'nickname' => 'mavinoo',
     *              'name' => 'mohammad',
     *          ],
     *      ],
     *      [
     *          'conditions' => ['id' => 3],
     *          'columns'    => [
     *              'nickname' => 'ali'
     *          ],
     *      ],
     * ];
     * $keyName = 'id';
     */
    public function updateMultipleCondition(array $arrays, ?string $keyName = null): int
    {
        return app(Batch::class)->updateMultipleCondition(self::batchModel(), $arrays, $keyName, ...array_slice(func_get_args(), 2));
    }

    /**
     * Insert multiple rows.
     *
     * Example:
     * ```
     * use App\Models\User;
     *
     * $columns = [
     *     'firstName',
     *     'lastName',
     *     'email',
     *     'isActive',
     *     'status',
     * ];
     * $values = [
     *     [
     *         'Mohammad',
     *         'Ghanbari',
     *         'emailSample_1@gmail.com',
     *         '1',
     *         '0',
     *     ] ,
     *     [
     *         'Saeed',
     *         'Mohammadi',
     *         'emailSample_2@gmail.com',
     *         '1',
     *         '0',
     *     ] ,
     *     [
     *         'Avin',
     *         'Ghanbari',
     *         'emailSample_3@gmail.com',
     *         '1',
     *         '0',
     *     ] ,
     * ];
     * $batchSize = 500; // insert 500 (default), 100 minimum rows in one query
     *
     * User::batchInsert($columns, $values, $batchSize);
     * ```
     *
     * @param  array<int, string>  $columns
     * @param  array<array-key, mixed>  $values
     * @param  int  $batchSize
     * @param  bool  $insertIgnore
     *
     * @return array{totalRows: int, totalBatch: int, totalQuery: int}
     */
    public static function batchInsert(array $columns, array $values, int $batchSize = 500, bool $insertIgnore = false): array
    {
        return app(Batch::class)->insert(self::batchModel(), $columns, $values, $batchSize, $insertIgnore);
    }

    /**
     * Update many rows, each matched on its own set of conditions.
     *
     * Example:
     * ```
     * User::batchUpdateMultipleCondition([
     *     ['conditions' => ['id' => 1, 'status' => 'active'], 'columns' => ['status' => 'invalid']],
     * ], 'id');
     * ```
     *
     * @param  array<array-key, mixed>  $values
     * @param  string|null  $index
     *
     * @return int number of affected rows
     */
    public static function batchUpdateMultipleCondition(array $values, ?string $index = null): int
    {
        return app(Batch::class)->updateMultipleCondition(self::batchModel(), $values, $index);
    }

    /**
     * Update many rows, matched on two columns.
     *
     * @param  array<array-key, mixed>  $values
     * @param  string|null  $index
     * @param  string|null  $index2
     *
     * @return int number of affected rows
     */
    public static function batchUpdateWithTwoIndex(array $values, ?string $index = null, ?string $index2 = null): int
    {
        return app(Batch::class)->updateWithTwoIndex(self::batchModel(), $values, $index, $index2);
    }

    /**
     * Update many rows, matched on any number of key columns.
     *
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, mixed>  $keys
     *
     * @return int number of affected rows
     */
    public static function batchUpdateByKeys(array $values, array $keys): int
    {
        return app(Batch::class)->updateByKeys(self::batchModel(), $values, $keys);
    }

    /**
     * Insert many rows given as column => value pairs.
     *
     * @param  array<array-key, mixed>  $rows
     * @param  int  $batchSize
     * @param  bool  $insertIgnore
     *
     * @return array{totalRows: int, totalBatch: int, totalQuery: int}
     */
    public static function batchInsertRows(array $rows, int $batchSize = 500, bool $insertIgnore = false): array
    {
        return app(Batch::class)->insertRows(self::batchModel(), $rows, $batchSize, $insertIgnore);
    }

    /**
     * Insert rows given as column => value pairs and return their auto-increment ids, in order.
     *
     * @param  array<array-key, mixed>  $rows
     * @param  int  $batchSize
     *
     * @return list<int>
     */
    public static function batchInsertGetIds(array $rows, int $batchSize = 500): array
    {
        return app(Batch::class)->insertGetIds(self::batchModel(), $rows, $batchSize);
    }

    /**
     * Import rows from a CSV / TSV / JSON Lines file, an open stream or an iterable, a chunk at a time.
     *
     * @param  mixed  $source
     * @param  array<string, mixed>  $options
     *
     * @return array{totalRows: int, skipped: int, errors: array<int, array<string, array<int, string>>>}
     */
    public static function batchImport(mixed $source, array $options = []): array
    {
        return app(Batch::class)->import(self::batchModel(), $source, $options);
    }

    /**
     * Insert rows, or update them when a row with the same $uniqueBy values already exists.
     *
     * @param  array<array-key, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<array-key, mixed>|null  $update
     * @param  array<string, mixed>  $options
     *
     * @return int number of affected rows, as reported by the database
     */
    public static function batchUpsert(array $values, $uniqueBy, ?array $update = null, array $options = []): int
    {
        return app(Batch::class)->upsert(self::batchModel(), $values, $uniqueBy, $update, $options);
    }

    /**
     * Upsert rows and return them as they are stored afterwards, in input order.
     *
     * @param  array<array-key, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<array-key, mixed>|null  $update
     * @param  list<string>  $returning
     * @param  array<string, mixed>  $options
     *
     * @return list<array<string, mixed>>
     */
    public static function batchUpsertReturning(array $values, $uniqueBy, ?array $update = null, array $returning = ['*'], array $options = []): array
    {
        return app(Batch::class)->upsertReturning(self::batchModel(), $values, $uniqueBy, $update, $returning, $options);
    }

    /**
     * Insert rows, skip the ones that hit a unique key, and report which were skipped.
     *
     * @param  array<array-key, mixed>  $rows
     * @param  array<int, string>|string  $uniqueBy
     * @param  int  $batchSize
     *
     * @return array{inserted: int, skipped: list<array<string, mixed>>}
     */
    public static function batchInsertOrIgnoreRows(array $rows, array|string $uniqueBy, int $batchSize = 500): array
    {
        return app(Batch::class)->insertOrIgnoreRows(self::batchModel(), $rows, $uniqueBy, $batchSize);
    }

    /**
     * Delete many rows, matched on one or more key columns. Soft deletes unless $force is true.
     *
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, mixed>  $keys
     * @param  bool  $force
     *
     * @return int number of deleted (or soft-deleted) rows
     */
    public static function batchDeleteByKeys(array $values, array $keys, bool $force = false): int
    {
        return app(Batch::class)->deleteByKeys(self::batchModel(), $values, $keys, $force);
    }

    /**
     * A fresh instance of the model, made through Eloquent so custom constructors are respected.
     */
    private static function batchModel(): Model
    {
        return static::query()->getModel();
    }
}
