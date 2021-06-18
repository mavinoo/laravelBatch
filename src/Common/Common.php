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

    protected static function safeJsonString($fieldValue){
        return str_replace(
            ["'"],
            ["\'"],
            $fieldValue
        );
    }

    protected static function is_json($str): bool
    {
        if (!is_string($str)){
            return false;
        }
        return json_decode($str, true) !== null;
    }

    protected static function safeJson($jsonData,$asArray = false){
        $jsonData = json_decode($jsonData,true);
        $safeJsonData = [];
        foreach ($jsonData as $key => $value){
            if (self::is_json($value)){
                $safeJsonData[$key] = self::safeJson($jsonData,true);
            }elseif(is_string($value)){
                $safeJsonData[$key] = $value;
            }else{
                $safeJsonData[$key] = $value;
            }
        }
        return $asArray ? $safeJsonData : json_encode($safeJsonData);
    }

}
