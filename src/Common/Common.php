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

        if(self::is_json($fieldValue)){
            return $fieldValue;
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

    protected static function is_json($str): bool
    {
        if (!is_string($str)){
            return false;
        }
        return json_decode($str, true) !== null;
    }
}
