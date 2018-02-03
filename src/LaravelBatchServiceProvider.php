<?php

namespace Mavinoo\LaravelBatch;

use Illuminate\Support\ServiceProvider;


class LaravelBatchServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind('Batch', function (){
            return new Batch;
        });
    }
}