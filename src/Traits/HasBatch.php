<?php

declare(strict_types=1);

namespace Mavinoo\Batch\Traits;

use Mavinoo\Batch\Batch;

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
     * @param  array  $values
     * @param  string|null  $index
     * @param  bool  $raw
     *
     * @return bool|int
     */
    public static function batchUpdate(array $values, string|null $index = null, bool $raw = false)
    {
        return app(Batch::class)->update(new static, $values, $index, $raw);
    }

    /**
     * Update multiple condition rows
     *
     * @param  array  $arrays
     * @param  string|null  $keyName
     * @param  bool  $raw
     *
     * @return bool|int
     * @createdBy Mohammad Ghanbari <mavin.developer@gmail.com>
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
    public function updateMultipleCondition(array $arrays, string|null $keyName = null, bool $raw = false)
    {
        return app(Batch::class)->updateMultipleCondition(new static, $arrays, $keyName, $raw);
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
     * @param  array  $columns
     * @param  array  $values
     * @param  int  $batchSize
     * @param  bool  $insertIgnore
     *
     * @return bool|array
     */
    public static function batchInsert(array $columns, array $values, int $batchSize = 500, bool $insertIgnore = false)
    {
        return app(Batch::class)->insert(new static, $columns, $values, $batchSize, $insertIgnore);
    }
}
