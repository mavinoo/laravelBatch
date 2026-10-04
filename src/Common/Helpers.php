<?php

declare(strict_types=1);

if (!function_exists('batch')) {
    /**
     * Batch helper to get Mavinoo\Batch\Batch instance.
     *
     * @return \Mavinoo\Batch\Batch
     */
    function batch()
    {
        return app('Mavinoo\Batch\Batch');
    }
}
