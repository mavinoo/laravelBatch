<?php declare(strict_types=1);

namespace Mavinoo\Batch;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\ServiceProvider;

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

    /**
     * Add the chunked methods to Eloquent and query builders:
     * Log::where(...)->deleteInChunks(), ->updateInChunks([...]) and ->archiveTo('table').
     */
    public function boot(): void
    {
        foreach ([EloquentBuilder::class, QueryBuilder::class] as $builder) {
            $builder::macro('deleteInChunks', function (...$arguments) {
                /** @var EloquentBuilder<\Illuminate\Database\Eloquent\Model>|QueryBuilder $this */
                return app(Batch::class)->deleteInChunks($this, ...$arguments);
            });

            $builder::macro('updateInChunks', function (...$arguments) {
                /** @var EloquentBuilder<\Illuminate\Database\Eloquent\Model>|QueryBuilder $this */
                return app(Batch::class)->updateInChunks($this, ...$arguments);
            });

            $builder::macro('archiveTo', function (...$arguments) {
                /** @var EloquentBuilder<\Illuminate\Database\Eloquent\Model>|QueryBuilder $this */
                return app(Batch::class)->archive($this, ...$arguments);
            });
        }
    }
}
