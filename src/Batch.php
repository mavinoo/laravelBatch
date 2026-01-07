<?php declare(strict_types=1);

namespace Mavinoo\Batch;

use Mavinoo\Batch\Common\Common;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

class Batch implements BatchInterface
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
     * <h2>Update multiple rows.</h2>
     *
     * Example:<br>
     * ```
     * $userInstance = new \App\Models\User;
     * $value = [
     *     [
     *         'id' => 1,
     *         'status' => 'active',
     *         'nickname' => 'Mohammad'
     *     ],
     *     [
     *         'id' => 5,
     *         'status' => 'deactive',
     *         'nickname' => 'Ghanbari'
     *     ],
     *     [
     *         'id' => 7,
     *         'balance' => ['+', 500]
     *     ]
     * ];
     * $index = 'id';
     * Batch::update($userInstance, $value, $index);
     * ```
     *
     * @param Model $table
     * @param array $values
     * @param string $index
     * @param bool $raw
     * @return bool|int
     * @createdBy Mohammad Ghanbari <mavin.developer@gmail.com>
     * @updatedBy Ibrahim Sakr <ebrahimes@gmail.com>
     */
    public function update(Model $table, array $values, string|null $index = null, bool $raw = false)
    {
        $final = [];
        $ids = [];
        $timestampConditions = []; // Track conditions for timestamp updates

        if (!count($values)) {
            return false;
        }

        if (!isset($index) || empty($index)) {
            $index = $table->getKeyName();
        }

        $driver = $table->getConnection()->getDriverName();
        $updatedAtColumn = null;
        $timestampValue = null;

        if ($table->usesTimestamps()) {
            $updatedAtColumn = $table->getUpdatedAtColumn();
            $timestampValue = Carbon::now()->format($table->getDateFormat());
        }

        foreach ($values as $key => $val) {
            $ids[] = $val[$index];
            $hasChanges = false;
            $changeConditions = [];

            foreach (array_keys($val) as $field) {
                if ($field !== $index && $field !== $updatedAtColumn) {
                    $hasChanges = true;
                    
                    // If increment / decrement
                    if (gettype($val[$field]) == 'array') {
                        // If array has two values
                        if (!array_key_exists(0, $val[$field]) || !array_key_exists(1, $val[$field])) {
                            throw new \ArgumentCountError('Increment/Decrement array needs to have 2 values, a math operator (+, -, *, /, %) and a number');
                        }
                        // Check first value
                        if (gettype($val[$field][0]) != 'string' || !in_array($val[$field][0], ['+', '-', '*', '/', '%'])) {
                            throw new \TypeError('First value in Increment/Decrement array needs to be a string and a math operator (+, -, *, /, %)');
                        }
                        // Check second value
                        if (!is_numeric($val[$field][1])) {
                            throw new \TypeError('Second value in Increment/Decrement array needs to be numeric');
                        }
                        // Increment / decrement
                        // For increment/decrement, always consider it a change
                        if (Common::disableBacktick($driver)) {
                            $value = $field . $val[$field][0] . $val[$field][1];
                            $changeConditions[] = '(' . $field . ' IS NOT NULL)';
                        } else {
                            $value = '`' . $field . '`' . $val[$field][0] . $val[$field][1];
                            $changeConditions[] = '(`' . $field . '` IS NOT NULL)';
                        }
                    } else {
                        // Only update
                        $finalField = $raw ? Common::mysql_escape($val[$field]) : "'" . Common::mysql_escape($val[$field]) . "'";
                        $value = (is_null($val[$field]) ? 'NULL' : $finalField);
                        
                        // Build condition to check if value actually changes
                        if (is_null($val[$field])) {
                            if (Common::disableBacktick($driver)) {
                                $changeConditions[] = '(' . $field . ' IS NOT NULL)';
                            } else {
                                $changeConditions[] = '(`' . $field . '` IS NOT NULL)';
                            }
                        } else {
                            if (Common::disableBacktick($driver)) {
                                $changeConditions[] = '(' . $field . ' != ' . $finalField . ')';
                            } else {
                                $changeConditions[] = '(`' . $field . '` != ' . $finalField . ')';
                            }
                        }
                    }

                    if (Common::disableBacktick($driver))
                        $final[$field][] = 'WHEN ' . $index . ' = \'' . $val[$index] . '\' THEN ' . $value . ' ';
                    else
                        $final[$field][] = 'WHEN `' . $index . '` = \'' . $val[$index] . '\' THEN ' . $value . ' ';
                }
            }

            // Handle explicit updated_at in values
            if (isset($val[$updatedAtColumn])) {
                $timestampFieldValue = $raw ? Common::mysql_escape($val[$updatedAtColumn]) : "'" . Common::mysql_escape($val[$updatedAtColumn]) . "'";
                $timestampFieldValue = (is_null($val[$updatedAtColumn]) ? 'NULL' : $timestampFieldValue);
                
                if (Common::disableBacktick($driver))
                    $final[$updatedAtColumn][] = 'WHEN ' . $index . ' = \'' . $val[$index] . '\' THEN ' . $timestampFieldValue . ' ';
                else
                    $final[$updatedAtColumn][] = 'WHEN `' . $index . '` = \'' . $val[$index] . '\' THEN ' . $timestampFieldValue . ' ';
            } elseif ($hasChanges && $updatedAtColumn && count($changeConditions) > 0) {
                // Only add timestamp update if there are actual field changes
                $indexCondition = Common::disableBacktick($driver) ? 
                    $index . ' = \'' . $val[$index] . '\'' : 
                    '`' . $index . '` = \'' . $val[$index] . '\'';
                
                $combinedCondition = $indexCondition . ' AND (' . implode(' OR ', $changeConditions) . ')';
                
                if (Common::disableBacktick($driver))
                    $timestampConditions[] = 'WHEN ' . $combinedCondition . ' THEN \'' . $timestampValue . '\' ';
                else
                    $timestampConditions[] = 'WHEN ' . $combinedCondition . ' THEN \'' . $timestampValue . '\' ';
            }
        }

        // Add timestamp conditions to final array if we have any
        if (!empty($timestampConditions) && $updatedAtColumn) {
            $final[$updatedAtColumn] = $timestampConditions;
        }

        if (Common::disableBacktick($driver)) {

            $cases = '';
            foreach ($final as $k => $v) {
                $cases .= '"' . $k . '" = (CASE ' . implode("\n", $v) . "\n"
                        . 'ELSE "' . $k . '" END), ';
            }

            $query = "UPDATE \"" . $this->getFullTableName($table) . '" SET ' . substr($cases, 0, -2) . " WHERE \"$index\" IN('" . implode("','", $ids) . "');";

        } else {

            $cases = '';
            foreach ($final as $k => $v) {
                $cases .= '`' . $k . '` = (CASE ' . implode("\n", $v) . "\n"
                        . 'ELSE `' . $k . '` END), ';
            }

            $query = "UPDATE `" . $this->getFullTableName($table) . "` SET " . substr($cases, 0, -2) . " WHERE `$index` IN(" . '"' . implode('","', $ids) . '"' . ");";

        }

        return $this->db->connection($this->getConnectionName($table))->update($query);
    }

    /**
     * Update multiple rows
     * @param Model $table
     * @param array $values
     * @param string $index
     * @param string|null $index2
     * @param bool $raw
     * @return bool|int
     * @createdBy Mohammad Ghanbari <mavin.developer@gmail.com>
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
     * $index2 = 'user_id';
     *
     */
    public function updateWithTwoIndex(Model $table, array $values, string|null $index = null, string|null $index2 = null, bool $raw = false)
    {
        $final = [];
        $ids = [];
        $ids2 = [];
        $timestampConditions = []; // Track conditions for timestamp updates
        
        if (!count($values)) {
            return false;
        }

        if (!isset($index) || empty($index)) {
            $index = $table->getKeyName();
        }

        $driver = $table->getConnection()->getDriverName();
        $updatedAtColumn = null;
        $timestampValue = null;

        if ($table->usesTimestamps()) {
            $updatedAtColumn = $table->getUpdatedAtColumn();
            $timestampValue = Carbon::now()->format($table->getDateFormat());
        }

        foreach ($values as $key => $val) {
            $ids[] = $val[$index];
            $ids2[] = $val[$index2];
            $hasChanges = false;
            $changeConditions = [];
            
            foreach (array_keys($val) as $field) {
                if ($field !== $index && $field !== $index2 && $field !== $updatedAtColumn) {
                    $hasChanges = true;
                    
                    // If increment / decrement
                    if (gettype($val[$field]) == 'array') {
                        // If array has two values
                        if (!array_key_exists(0, $val[$field]) || !array_key_exists(1, $val[$field])) {
                            throw new \ArgumentCountError('Increment/Decrement array needs to have 2 values, a math operator (+, -, *, /, %) and a number');
                        }
                        // Check first value
                        if (gettype($val[$field][0]) != 'string' || !in_array($val[$field][0], ['+', '-', '*', '/', '%'])) {
                            throw new \TypeError('First value in Increment/Decrement array needs to be a string and a math operator (+, -, *, /, %)');
                        }
                        // Check second value
                        if (!is_numeric($val[$field][1])) {
                            throw new \TypeError('Second value in Increment/Decrement array needs to be numeric');
                        }
                        // Increment / decrement
                        // For increment/decrement, always consider it a change
                        if (Common::disableBacktick($driver)) {
                            $value = $field . $val[$field][0] . $val[$field][1];
                            $changeConditions[] = '(' . $field . ' IS NOT NULL)';
                        } else {
                            $value = '`' . $field . '`' . $val[$field][0] . $val[$field][1];
                            $changeConditions[] = '(`' . $field . '` IS NOT NULL)';
                        }
                    } else {
                        // Only update
                        $finalField = $raw ? Common::mysql_escape($val[$field]) : "'" . Common::mysql_escape($val[$field]) . "'";
                        $value = (is_null($val[$field]) ? 'NULL' : $finalField);
                        
                        // Build condition to check if value actually changes
                        if (is_null($val[$field])) {
                            if (Common::disableBacktick($driver)) {
                                $changeConditions[] = '(' . $field . ' IS NOT NULL)';
                            } else {
                                $changeConditions[] = '(`' . $field . '` IS NOT NULL)';
                            }
                        } else {
                            if (Common::disableBacktick($driver)) {
                                $changeConditions[] = '(' . $field . ' != ' . $finalField . ')';
                            } else {
                                $changeConditions[] = '(`' . $field . '` != ' . $finalField . ')';
                            }
                        }
                    }

                    if (Common::disableBacktick($driver)) {
                        $final[$field][] = 'WHEN (' . $index . ' = \'' . Common::mysql_escape($val[$index]) . '\' AND ' . $index2 . ' = \'' . $val[$index2] . '\') THEN ' . $value . ' ';
                    } else {
                        $final[$field][] = 'WHEN (`' . $index . '` = "' . Common::mysql_escape($val[$index]) . '" AND `' . $index2 . '` = "' . $val[$index2] . '") THEN ' . $value . ' ';
                    }
                }
            }

            // Handle explicit updated_at in values
            if (isset($val[$updatedAtColumn])) {
                $timestampFieldValue = $raw ? Common::mysql_escape($val[$updatedAtColumn]) : "'" . Common::mysql_escape($val[$updatedAtColumn]) . "'";
                $timestampFieldValue = (is_null($val[$updatedAtColumn]) ? 'NULL' : $timestampFieldValue);
                
                if (Common::disableBacktick($driver)) {
                    $final[$updatedAtColumn][] = 'WHEN (' . $index . ' = \'' . Common::mysql_escape($val[$index]) . '\' AND ' . $index2 . ' = \'' . $val[$index2] . '\') THEN ' . $timestampFieldValue . ' ';
                } else {
                    $final[$updatedAtColumn][] = 'WHEN (`' . $index . '` = "' . Common::mysql_escape($val[$index]) . '" AND `' . $index2 . '` = "' . $val[$index2] . '") THEN ' . $timestampFieldValue . ' ';
                }
            } elseif ($hasChanges && $updatedAtColumn && count($changeConditions) > 0) {
                // Only add timestamp update if there are actual field changes
                $indexCondition = Common::disableBacktick($driver) ? 
                    '(' . $index . ' = \'' . Common::mysql_escape($val[$index]) . '\' AND ' . $index2 . ' = \'' . $val[$index2] . '\')' : 
                    '(`' . $index . '` = "' . Common::mysql_escape($val[$index]) . '" AND `' . $index2 . '` = "' . $val[$index2] . '")';
                
                $combinedCondition = $indexCondition . ' AND (' . implode(' OR ', $changeConditions) . ')';
                
                if (Common::disableBacktick($driver))
                    $timestampConditions[] = 'WHEN ' . $combinedCondition . ' THEN \'' . $timestampValue . '\' ';
                else
                    $timestampConditions[] = 'WHEN ' . $combinedCondition . ' THEN \'' . $timestampValue . '\' ';
            }
        }

        // Add timestamp conditions to final array if we have any
        if (!empty($timestampConditions) && $updatedAtColumn) {
            $final[$updatedAtColumn] = $timestampConditions;
        }


        if (Common::disableBacktick($driver)) {
            $cases = '';
            foreach ($final as $k => $v) {
                $cases .= '"' . $k . '" = (CASE ' . implode("\n", $v) . "\n"
                        . 'ELSE "' . $k . '" END), ';
            }

            $query = "UPDATE \"" . $this->getFullTableName($table) . '" SET ' . substr($cases, 0, -2) . " WHERE \"$index\" IN('" . implode("','", $ids) . "') AND \"$index2\" IN('" . implode("','", $ids2) . "');";
            //$query = "UPDATE \"" . $this->getFullTableName($table) . "\" SET " . substr($cases, 0, -2) . " WHERE \"$index\" IN(" . '"' . implode('","', $ids) . '")' . " AND \"$index2\" IN(" . '"' . implode('","', $ids2) . '"' . " );";
        } else {
            $cases = '';
            foreach ($final as $k => $v) {
                $cases .= '`' . $k . '` = (CASE ' . implode("\n", $v) . "\n"
                        . 'ELSE `' . $k . '` END), ';
            }
            $query = "UPDATE `" . $this->getFullTableName($table) . "` SET " . substr($cases, 0, -2) . " WHERE `$index` IN(" . '"' . implode('","', $ids) . '")' . " AND `$index2` IN(" . '"' . implode('","', $ids2) . '"' . " );";
        }

        return $this->db->connection($this->getConnectionName($table))->update($query);
    }

    /**
     * Update multiple condition rows
     *
     * @param  Model  $table
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
    public function updateMultipleCondition(Model $table, array $arrays, string|null $keyName = null, bool $raw = false)
    {
        $driver = $table->getConnection()->getDriverName();
        $connectionName = $this->getConnectionName($table);
        $tableName = $this->getFullTableName($table);
        $timestamp = $table->usesTimestamps();
        $backtick = Common::disableBacktick($driver) ? '`' : '';

        if (!count($arrays)) {
            return false;
        }

        if (!isset($keyName) || empty($keyName)) {
            $keyName = $table->getKeyName();
        }

        $columns = [];
        $conditionMaster = [];
        foreach ($arrays as $array) {
            foreach ($array['conditions'] as $keyCondition => $condition) {
                if ($keyName == $keyCondition and !in_array($condition, $conditionMaster)) {
                    $conditionMaster[] = str(Common::mysql_escape($condition))->toString();
                }
            }
            foreach ($array as $key => $item) {
                if ($key == 'columns') {
                    foreach ($item as $k => $value) {
                        if (!in_array($key, $columns)) {
                            $columns[$k] = $k;
                        }
                    }
                }
            }
        }

        $updatedAtColumn = null;
        $timestampValue = null;
        
        if ($timestamp) {
            $updatedAtColumn = $table->getUpdatedAtColumn();
            $timestampValue = Carbon::now()->format($table->getDateFormat());
        }

        $arraysNew = [];
        $timestampConditions = []; // Track conditions for timestamp updates
        $keys = array_keys($columns);
        
        foreach ($keys as $key) {
            $arraysMixed = collect($arrays)->filter(function ($rows) use ($key) {
                return in_array($key, array_keys($rows['columns']));
            });

            foreach ($arraysMixed as $item) {
                $value = $raw ? Common::mysql_escape($item['columns'][$key]) : "'" . Common::mysql_escape($item['columns'][$key]) . "'";
                $arraysNew[$key][] = [
                        'conditions' => $item['conditions'],
                        'value'      => is_null($item['columns'][$key]) ? "NULL" : $value,
                        'originalValue' => $item['columns'][$key], // Store original value for change detection
                ];
            }
        }

        // Handle timestamp updates with change detection
        if ($timestamp && $updatedAtColumn) {
            foreach ($arrays as $item) {
                $hasChanges = false;
                $changeConditions = [];
                
                // Check if this item has any actual field changes
                foreach ($item['columns'] as $fieldName => $newValue) {
                    if ($fieldName !== $updatedAtColumn) {
                        $hasChanges = true;
                        
                        // Build condition to check if value actually changes
                        if (is_null($newValue)) {
                            $changeConditions[] = " {$backtick}{$fieldName}{$backtick} IS NOT NULL ";
                        } else {
                            $escapedValue = $raw ? Common::mysql_escape($newValue) : "'" . Common::mysql_escape($newValue) . "'";
                            $changeConditions[] = " {$backtick}{$fieldName}{$backtick} != {$escapedValue} ";
                        }
                    }
                }

                // Handle explicit updated_at in columns
                if (isset($item['columns'][$updatedAtColumn])) {
                    $timestampFieldValue = $raw ? Common::mysql_escape($item['columns'][$updatedAtColumn]) : "'" . Common::mysql_escape($item['columns'][$updatedAtColumn]) . "'";
                    $timestampFieldValue = (is_null($item['columns'][$updatedAtColumn]) ? 'NULL' : $timestampFieldValue);
                    
                    $arraysNew[$updatedAtColumn][] = [
                        'conditions' => $item['conditions'],
                        'value'      => $timestampFieldValue,
                        'originalValue' => $item['columns'][$updatedAtColumn],
                    ];
                } elseif ($hasChanges && count($changeConditions) > 0) {
                    // Only add timestamp update if there are actual field changes
                    $conditionsWithChanges = $item['conditions'];
                    $timestampConditions[] = [
                        'conditions' => $conditionsWithChanges,
                        'value' => "'" . $timestampValue . "'",
                        'changeConditions' => $changeConditions, // Store change conditions for SQL generation
                    ];
                }
            }
            
            // Add timestamp conditions with change detection to arraysNew
            if (!empty($timestampConditions)) {
                if (!isset($arraysNew[$updatedAtColumn])) {
                    $arraysNew[$updatedAtColumn] = [];
                }
                
                foreach ($timestampConditions as $timestampCondition) {
                    $arraysNew[$updatedAtColumn][] = $timestampCondition;
                }
            }
        }

        $cases = [];
        foreach ($arraysNew as $key => $items) {
            $caseSql = "{$backtick}{$key}{$backtick} = (CASE ";
            foreach ($items as $item) {
                $conditions = $item['conditions'];
                $value = $item['value'];

                $conditionContext = [];
                foreach ($conditions as $conditionKey => $condition) {
                    $conditionContext[] = " {$backtick}{$conditionKey}{$backtick} = '{$condition}' ";
                }

                $conditionContext = join(' and ', $conditionContext);
                
                // Handle timestamp update with change conditions
                if ($key === $updatedAtColumn && isset($item['changeConditions']) && !empty($item['changeConditions'])) {
                    // Add change detection conditions to only update timestamp when fields actually change
                    $changeConditionsSql = implode(' OR ', $item['changeConditions']);
                    $caseSql .= " WHEN ($conditionContext) AND ($changeConditionsSql) THEN {$value} ";
                } else {
                    $caseSql .= " WHEN $conditionContext THEN {$value} ";
                }
            }
            $caseSql .= " ELSE {$backtick}{$key}{$backtick} END)";
            $cases[] = $caseSql;
        }
        $caseSql = join(', ', $cases);
        $conditionMaster = join(', ', $conditionMaster);

        $query = "update {$backtick}{$tableName}{$backtick} set {$caseSql} where {$backtick}{$keyName}{$backtick} in ({$conditionMaster})";

        return $this->db->connection($connectionName)->update($query);
    }

    /**
     * Insert Multi rows.
     *
     * @param Model $table
     * @param array $columns
     * @param array $values
     * @param int $batchSize
     * @param bool $insertIgnore
     * @return bool|mixed
     * @throws \Throwable
     * @createdBy Mohammad Ghanbari <mavin.developer@gmail.com>
     * @updatedBy Ibrahim Sakr <ebrahimes@gmail.com>
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
    public function insert(Model $table, array $columns, array $values, int $batchSize = 500, bool $insertIgnore = false)
    {
        // no need for the old validation since we now use type hint that supports from php 7.0
        // but I kept this one
        if (count($columns) !== count(current($values))) {
            return false;
        }

        $query = [];
        $minChunck = 100;

        $totalValues = count($values);
        $batchSizeInsert = ($totalValues < $batchSize && $batchSize < $minChunck) ? $minChunck : $batchSize;

        $totalChunk = ($batchSizeInsert < $minChunck) ? $minChunck : $batchSizeInsert;

        $values = array_chunk($values, $totalChunk, true);

        if ($table->usesTimestamps()) {
            $createdAtColumn = $table->getCreatedAtColumn();
            $updatedAtColumn = $table->getUpdatedAtColumn();
            $now = Carbon::now()->format($table->getDateFormat());

            $addCreatedAtValue = false;
            $addUpdatedAtValue = false;

            if (!in_array($createdAtColumn, $columns)) {
                $addCreatedAtValue = true;
                array_push($columns, $createdAtColumn);
            }

            if (!in_array($updatedAtColumn, $columns)) {
                $addUpdatedAtValue = true;
                array_push($columns, $updatedAtColumn);
            }

            foreach ($values as $key => $value) {
                foreach ($value as $rowKey => $row) {
                    if ($addCreatedAtValue) {
                        array_push($values[$key][$rowKey], $now);
                    }

                    if ($addUpdatedAtValue) {
                        array_push($values[$key][$rowKey], $now);
                    }
                }
            }
        }

        $driver = $table->getConnection()->getDriverName();

        if (Common::disableBacktick($driver)) {
            foreach ($columns as $key => $column) {
                $columns[$key] = '"' . Common::mysql_escape($column) . '"';
            }
        } else {
            foreach ($columns as $key => $column) {
                $columns[$key] = '`' . Common::mysql_escape($column) . '`';
            }
        }

        foreach ($values as $value) {
            $valueArray = [];
            foreach ($value as $data) {
                foreach ($data as $key => $item) {
                    $item = is_null($item) ? 'NULL' : "'" . Common::mysql_escape($item) . "'";
                    $data[$key] = $item;
                }

                $valueArray[] = '(' . implode(',', $data) . ')';
            }

            $valueString = implode(', ', $valueArray);

            $ignoreStmt = $insertIgnore ? ' IGNORE ' : '';

            if (Common::disableBacktick($driver)) {
                $query[] = 'INSERT ' . $ignoreStmt . ' INTO "' . $this->getFullTableName($table) . '" (' . implode(',', $columns) . ") VALUES $valueString;";
            } else {
                $query[] = 'INSERT ' . $ignoreStmt . ' INTO `' . $this->getFullTableName($table) . '` (' . implode(',', $columns) . ") VALUES $valueString;";
            }
        }

        if (count($query)) {
            return $this->db->transaction(function () use ($totalValues, $totalChunk, $query, $table) {
                $totalQuery = 0;
                foreach ($query as $value) {
                    $totalQuery += $this->db->connection($this->getConnectionName($table))->statement($value) ? 1 : 0;
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
     * Get full table name.
     *
     * @param Model $model
     * @return string
     * @author Ibrahim Sakr <ebrahimes@gmail.com>
     */
    private function getFullTableName(Model $model)
    {
        return $model->getConnection()->getTablePrefix() . $model->getTable();
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
