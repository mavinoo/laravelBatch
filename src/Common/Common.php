<?php

namespace Mavinoo\LaravelBatch\Common;

class Common
{
    public static function mysql_escape($inp)
    {
        if(is_array($inp)) return array_map(__METHOD__, $inp);

        if(!empty($inp) && is_string($inp))
        {
            return str_replace(
                ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
                ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
                $inp);
        }

        return $inp;
    }
}
