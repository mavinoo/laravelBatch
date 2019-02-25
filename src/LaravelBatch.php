<?php

namespace Mavinoo\LaravelBatch;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Mavinoo\LaravelBatch\Common\Helpers;

class Batch
{
    /**
     * @var DatabaseManager
     */
    protected $db;

    public function __construct(DatabaseManager $db)
    {
        $this->db = $db;
    }

    /**
     * Update multiple rows
     * @param Model $table
     * @param array $values
     * @param string $index
     * @updatedBy Ibrahim Sakr <ebrahimes@gmail.com>
     *
     * @desc
     * Example
     * $table = 'users';
     * $value = [
     *     [
     *         'id' => 1,
     *         'status' => 'active',
     *         'nickname' => 'Mohammad'
     *     ] ,
     *     [
     *         'id' => 5,
     *         'status' => 'deactive',
     *         'nickname' => 'Ghanbari'
     *     ] ,
     * ];
     * $index = 'id';
     *
     * @return bool|int
     */
    public function update(Model $table, array $values, string $index)
    {
        $final = [];
        $ids = [];

        if (!count($values)) {
            return false;
        }
        if (!isset($index) && empty($index)) {
            return false;
        }

        foreach ($values as $key => $val) {
            $ids[] = $val[$index];
            foreach (array_keys($val) as $field) {
                if ($field !== $index) {
                    $value = (is_null($val[$field]) ? 'NULL' : '"' . Helpers::mysql_escape($val[$field]) . '"');
                    $final[$field][] = 'WHEN `' . $index . '` = "' . $val[$index] . '" THEN ' . $value . ' ';
                }
            }
        }

        $cases = '';
        foreach ($final as $k => $v) {
            $cases .= '`' . $k . '` = (CASE ' . implode("\n", $v) . "\n"
                . 'ELSE `' . $k . '` END), ';
        }

        $query = "UPDATE `" . $this->getFullTableName($table) . "` SET " . substr($cases, 0, -2) . " WHERE `$index` IN(" . '"' . implode('","', $ids) . '"' . ");";

        return $this->db->connection($this->getConnectionName($table))->update($query);
    }

    /**
     * Insert Multi rows
     * @param Model $table
     * @param array $columns
     * @param array $values
     * @param int $batchSize
     * @return bool|mixed
     * @throws \Throwable
     * @updatedBy Ibrahim Sakr <ebrahimes@gmail.com>
     *
     * @desc
     * Example
     *
     * $table = 'users';
     * $columns = [
     *      'firstName',
     *      'lastName',
     *      'email',
     *      'isActive',
     *      'status',
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
     */
    public function insert(Model $table, array $columns, array $values, int $batchSize = 500)
    {
        // no need for the old validation since we now use type hint that supports from php 7.0
        // but I kept this one
        if (count($columns) != count($values[0])) {
            return false;
        }

        $query = [];
        $minChunck = 100;

        $totalValues = count($values);
        $batchSizeInsert = ($totalValues < $batchSize && $batchSize < $minChunck) ? $minChunck : $batchSize;

        $totalChunk = ($batchSizeInsert < $minChunck) ? $minChunck : $batchSizeInsert;

        $values = array_chunk($values, $totalChunk, true);

        foreach ($columns as $key => $column) {
            $columns[$key] = "`" . Helpers::mysql_escape($column) . "`";
        }

        foreach ($values as $value) {
            $valueArray = [];
            foreach ($value as $data) {
                foreach ($data as $key => $item) {
                    $item = is_null($item) ? 'NULL' : "'" . Helpers::mysql_escape($item) . "'";
                    $data[$key] = $item;
                }

                $valueArray[] = '(' . implode(',', $data) . ')';
            }

            $valueString = implode(', ', $valueArray);

            $query [] = "INSERT INTO `" . $this->getFullTableName($table) . "` (" . implode(',', $columns) . ") VALUES $valueString;";

        }

        if (count($query)) {
            return $this->db->transaction(function () use ($totalValues, $totalChunk, $query) {

                $totalQuery = 0;
                foreach ($query as $value) {
                    $totalQuery += $this->db->statement($value) ? 1 : 0;
                }

                return [
                    'totalRows' => $totalValues,
                    'totalBatch' => $totalChunk,
                    'totalQuery' => $totalQuery
                ];
            });
        }

        return false;
    }

    /**
     * @param Model $model
     * @return string
     * @author Ibrahim Sakr <ebrahimes@gmail.com>
     */
    private function getFullTableName(Model $model)
    {
        return $model->getConnection()->getTablePrefix() . $model->getTable();
    }

    /**
     * @param Model $model
     * @return string|null
     * @author Ibrahim Sakr <ebrahimes@gmail.com>
     */
    private function getConnectionName(Model $model)
    {
        if (!is_null($cn = $model->getConnectionName()))
            return $cn;

        return $model->getConnection()->getName();
    }
}
