<?php

namespace Mavinoo\UpdateBatch;

use Illuminate\Support\ServiceProvider;


class UpdateBatchServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind('UpdateBatch', function (){
            return new UpdateBatch;
        });
    }
}