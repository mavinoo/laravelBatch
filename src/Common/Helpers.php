<?php

declare(strict_types=1);

use Illuminate\Support\Str;

if (!function_exists('batch')) {
    /**
     * Batch helper to get Mavino\Batch\Batch instance.
     *
     * @return mixed
     */
    function batch()
    {
        return app('Mavinoo\Batch\Batch');
    }
}

if (!function_exists('str')) {
    /**
     * Get a new stringable object from the given string.
     *
     * @param  string|null  $string
     *
     * @return \Illuminate\Support\Stringable|mixed
     */
    function str($string = null)
    {
        if (func_num_args() === 0) {
            return new class {
                public function __call($method, $parameters)
                {
                    return Str::$method(...$parameters);
                }

                public function __toString()
                {
                    return '';
                }
            };
        }

        return Str::of($string);
    }
}