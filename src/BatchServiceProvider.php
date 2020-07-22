<?php declare(strict_types=1);

namespace Mavinoo\Batch;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\DatabaseManager;

class BatchServiceProvider extends ServiceProvider
{
    /**
     * Register Batch instance to IOC.
     *
     * @updateedBy Ibrahim Sakr <ebrahimes@gmail.com>
     */
    public function register()
    {
        $this->app->bind('Batch', function ($app) {
            return new Batch($app->make(DatabaseManager::class));
        });
    }
}
