<?php

namespace Mavinoo\LaravelBatch;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\DatabaseManager;

class LaravelBatchServiceProvider extends ServiceProvider
{
    /**
     * @updateedBy Ibrahim Sakr <ebrahimes@gmail.com>
     */
    public function register()
    {
        $this->app->bind('LaravelBatch', function ($app){
            return new LaravelBatch($app->make(DatabaseManager::class));
        });
    }
}
