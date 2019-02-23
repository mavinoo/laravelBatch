<?php

namespace Mavinoo\LaravelBatch;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\DatabaseManager;

class LaravelBatchServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(Batch::class, function ($app){
            return new Batch($app->make(DatabaseManager::class));
        });
    }
}
