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
        $this->app->bind(Batch::class, function ($app){
            return new Batch($app->make(DatabaseManager::class));
        });
    }
}
