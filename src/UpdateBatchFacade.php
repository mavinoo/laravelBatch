<?php

namespace Mavinoo\UpdateBatch;

use Illuminate\Support\Facades\Facade;

class UpdateBatchFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
       return 'UpdateBatch';
    }
}