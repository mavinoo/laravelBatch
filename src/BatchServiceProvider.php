<?php

namespace Mavinoo\Batch;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\DatabaseManager;
use Mavinoo\Batch\Batch;

class BatchServiceProvider extends ServiceProvider
{
    /**
     * @updateedBy Ibrahim Sakr <ebrahimes@gmail.com>
     */
    public function register()
    {
        $this->app->bind('Batch', function ($app){
            return new Batch($app->make(DatabaseManager::class));
        });
    }
}
