<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mavinoo\Batch\Batch;
use Mavinoo\Batch\BatchFacade;
use Mavinoo\Batch\BatchServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** Timestamp the seeded rows start with. */
    protected const OLD = '2020-01-01 00:00:00';

    /** Frozen "now" for every test. */
    protected const NOW = '2026-01-02 03:04:05';

    protected function getPackageProviders($app)
    {
        return [BatchServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return ['Batch' => BatchFacade::class];
    }

    protected function defineEnvironment($app)
    {
        $connection = $this->connectionConfig((string) env('DB_CONNECTION', 'sqlite'));

        $app['config']->set('database.default', 'testing');
        // A table prefix makes sure generated SQL always goes through the grammar.
        $app['config']->set('database.connections.testing', $connection + ['prefix' => 'batch_']);
        // A second connection, for models that don't use the default one.
        $app['config']->set('database.connections.secondary', $connection + ['prefix' => 'secondary_']);
    }

    protected function connectionConfig(string $driver): array
    {
        switch ($driver) {
            case 'sqlite':
                return ['driver' => 'sqlite', 'database' => ':memory:'];
            case 'mysql':
            case 'mariadb':
                return [
                    'driver' => $driver,
                    'host' => env('DB_HOST', '127.0.0.1'),
                    'port' => env('DB_PORT', '3306'),
                    'unix_socket' => env('DB_SOCKET', ''),
                    'database' => env('DB_DATABASE', 'batch_test'),
                    'username' => env('DB_USERNAME', 'root'),
                    'password' => env('DB_PASSWORD', ''),
                ];
            case 'pgsql':
                return [
                    'driver' => 'pgsql',
                    'host' => env('DB_HOST', '127.0.0.1'),
                    'port' => env('DB_PORT', '5432'),
                    'database' => env('DB_DATABASE', 'batch_test'),
                    'username' => env('DB_USERNAME', 'postgres'),
                    'password' => env('DB_PASSWORD', ''),
                ];
        }

        throw new \InvalidArgumentException("Unsupported DB_CONNECTION [{$driver}].");
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['testing', 'secondary'] as $connection) {
            $schema = Schema::connection($connection);
            $schema->dropIfExists('users');
            $schema->create('users', function (Blueprint $table) use ($connection) {
                $table->increments('id');
                $table->string('code')->nullable();
                // Index names are per schema in PostgreSQL, so keep them distinct per connection.
                $table->unique('code', "{$connection}_users_code_unique");
                $table->integer('org')->default(1);
                $table->string('name')->nullable();
                $table->string('phone')->nullable();
                $table->boolean('active')->default(true);
                $table->integer('balance')->default(100);
                $table->text('meta')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        Carbon::setTestNow(self::NOW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        // Close connections, so a long run doesn't exhaust the server's connection limit.
        foreach ($this->app['db']->getConnections() as $connection) {
            $connection->disconnect();
        }

        parent::tearDown();
    }

    protected function batch(): Batch
    {
        return $this->app->make(Batch::class);
    }

    /**
     * Seed rows 1..$count with code "c{i}", name "name{i}" and old timestamps.
     */
    protected function seedUsers(int $count = 3, string $connection = 'testing'): void
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['id' => $i, 'code' => "c{$i}", 'name' => "name{$i}", 'created_at' => self::OLD, 'updated_at' => self::OLD];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::connection($connection)->table('users')->insert($chunk);
        }

        // PostgreSQL doesn't move the id sequence past explicitly inserted ids.
        $db = DB::connection($connection);
        if ($count && $db->getDriverName() === 'pgsql') {
            $table = $db->getTablePrefix() . 'users';
            $db->statement("select setval(pg_get_serial_sequence('{$table}', 'id'), (select max(id) from {$table}))");
        }
    }

    protected function row(int $id, string $connection = 'testing'): ?object
    {
        return DB::connection($connection)->table('users')->where('id', $id)->first();
    }

    protected function countRows(string $connection = 'testing'): int
    {
        return DB::connection($connection)->table('users')->count();
    }

    /**
     * Run the callback and return the INSERT / UPDATE statements it sent.
     */
    protected function writeQueries(callable $callback, string $connection = 'testing'): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries, $connection) {
            if ($query->connectionName === $connection && preg_match('/^\s*(insert|update)/i', $query->sql)) {
                $queries[] = $query->sql;
            }
        });

        $callback();

        return $queries;
    }
}
