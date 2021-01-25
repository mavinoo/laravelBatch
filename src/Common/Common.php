<?php declare(strict_types=1);

namespace Mavinoo\Batch\Common;

class Common
{
    /**
     * Escape values according to mysql.
     *
     * @param $fieldValue
     * @return array|string|string[]
     */
    public static function mysql_escape($fieldValue)
    {
        if (is_array($fieldValue)) {
            return array_map(__METHOD__, $fieldValue);
        }

        if (is_bool($fieldValue)) {
            return (int) $fieldValue;
        }

        if (!empty($fieldValue) && is_string($fieldValue)) {
            return str_replace(
                ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
                ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
                $fieldValue
            );
        }

        return $fieldValue;
    }

    /**
     * Convert boolean values to 0 or 1.
     *
     * @param $fieldValue
     * @return array|string|bool|string[]|bool[]
     */
    public static function convertBoolean($fieldValue)
    {
        if (is_array($fieldValue)) {
            return array_map(__METHOD__, $fieldValue);
        }

        if (is_bool($fieldValue)) {
            return (int) $fieldValue;
        }

        return $fieldValue;
    }
}
