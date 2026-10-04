<?php declare(strict_types=1);

namespace Mavinoo\Batch;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\DatabaseManager;

class BatchServiceProvider extends ServiceProvider
{
    /**
     * Register Batch instance to IOC.
     *
     * One shared instance, so the facade, the batch() helper and the HasBatch trait all see
     * the same Batch::pretend() state.
     *
     * @updateedBy Ibrahim Sakr <ebrahimes@gmail.com>
     */
    public function register()
    {
        $this->app->singleton(Batch::class, function ($app) {
            return new Batch($app->make(DatabaseManager::class));
        });

        $this->app->alias(Batch::class, 'Batch');
    }
}
