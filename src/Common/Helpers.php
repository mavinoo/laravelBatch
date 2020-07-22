<?php declare(strict_types=1);

if (! function_exists('batch')) {
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
