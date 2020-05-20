<?php

namespace Mavinoo\Batch;

use Illuminate\Support\Facades\Facade;

class BatchFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
       return 'Batch';
    }
}
