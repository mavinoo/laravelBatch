<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Mavinoo\Batch\Batch;
use Mavinoo\Batch\BatchFacade;
use Mavinoo\Batch\Tests\Fixtures\SecondaryUser;
use Mavinoo\Batch\Tests\Fixtures\SmallBatch;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;
use RuntimeException;

class PretendTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedUsers();
    }

    public function test_update_is_recorded_but_not_run(): void
    {
        $queries = $this->batch()->pretend(function (Batch $batch) {
            $this->assertSame(0, $batch->update(new User, [['id' => 1, 'name' => "o'reilly"]]));
        });

        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('update ', strtolower($queries[0]['sql']));
        $this->assertContains("o'reilly", $queries[0]['bindings']);
        $this->assertSame('testing', $queries[0]['connection']);
        $this->assertSame('name1', $this->row(1)->name);
    }

    public function test_every_method_is_recorded(): void
    {
        $queries = $this->batch()->pretend(function (Batch $batch) {
            $batch->insert(new User, ['id', 'code'], [[10, 'c10']]);
            $batch->insertRows(new User, [['id' => 11, 'code' => 'c11']]);
            $batch->updateWithTwoIndex(new User, [['id' => 1, 'org' => 1, 'name' => 'a']], 'id', 'org');
            $batch->updateByKeys(new User, [['id' => 1, 'code' => 'c1', 'name' => 'b']], ['id', 'code']);
            $batch->updateMultipleCondition(new User, [['conditions' => ['id' => 1], 'columns' => ['name' => 'c']]]);
            $batch->upsert(new User, [['code' => 'c1', 'name' => 'd']], ['code']);
        });

        $this->assertCount(6, $queries);
        $this->assertStringStartsWith('insert', strtolower($queries[0]['sql']));
        $this->assertStringStartsWith('insert', strtolower($queries[1]['sql']));
        $this->assertStringStartsWith('update', strtolower($queries[2]['sql']));
        $this->assertStringStartsWith('insert', strtolower($queries[5]['sql']));
        $this->assertSame(3, $this->countRows());
        $this->assertSame('name1', $this->row(1)->name);
    }

    public function test_each_split_query_is_recorded(): void
    {
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = [$i + 100, "c{$i}x"];
        }

        $queries = (new SmallBatch($this->app['db']))->pretend(function (Batch $batch) use ($rows) {
            $this->assertSame(3, $batch->insert(new User, ['id', 'code'], $rows)['totalQuery']);
        });

        $this->assertCount(3, $queries);
        $this->assertSame(3, $this->countRows());
    }

    public function test_facade_helper_and_trait_share_the_pretend_state(): void
    {
        $queries = BatchFacade::pretend(function () {
            BatchFacade::update(new User, [['id' => 1, 'name' => 'facade']]);
            batch()->update(new User, [['id' => 2, 'name' => 'helper']]);
            User::batchUpdate([['id' => 3, 'name' => 'trait']]);
        });

        $this->assertCount(3, $queries);
        $this->assertSame('name1', $this->row(1)->name);
        $this->assertSame('name3', $this->row(3)->name);
    }

    public function test_queries_on_another_connection_are_recorded_with_its_name(): void
    {
        $queries = $this->batch()->pretend(function (Batch $batch) {
            $batch->insert(new SecondaryUser, ['id', 'code'], [[1, 'c1']]);
        });

        $this->assertSame('secondary', $queries[0]['connection']);
        $this->assertSame(0, $this->countRows('secondary'));
    }

    public function test_no_database_connection_is_opened(): void
    {
        // A MySQL connection to a host that doesn't exist: any attempt to connect would fail.
        $this->app['config']->set('database.connections.unreachable', [
            'driver' => 'mysql', 'host' => 'unreachable.invalid', 'database' => 'x', 'username' => 'x', 'password' => '',
        ]);
        $model = new class extends Model {
            protected $table = 'users';
            protected $connection = 'unreachable';
        };

        $queries = $this->batch()->pretend(function (Batch $batch) use ($model) {
            $batch->update($model, [['id' => 1, 'name' => 'x']]);
            $batch->insert($model, ['id', 'name'], [[1, 'x'], [2, 'y']]);
            $batch->upsert($model, [['id' => 1, 'name' => 'x']], ['id']);
        });

        $this->assertCount(3, $queries);
        $this->assertStringContainsString('`users`', $queries[0]['sql']);
        $this->assertStringContainsString('on duplicate key update', strtolower($queries[2]['sql']));
    }

    public function test_queries_run_normally_after_pretend_even_when_it_throws(): void
    {
        try {
            $this->batch()->pretend(function () {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $e) {
            // expected
        }

        $this->batch()->update(new User, [['id' => 1, 'name' => 'real']]);

        $this->assertSame('real', $this->row(1)->name);
    }

    public function test_nested_pretend_records_into_the_outer_call(): void
    {
        $queries = $this->batch()->pretend(function (Batch $batch) {
            $inner = $batch->pretend(function (Batch $batch) {
                $batch->update(new User, [['id' => 1, 'name' => 'x']]);
            });
            $this->assertSame([], $inner);
        });

        $this->assertCount(1, $queries);
        $this->assertSame('name1', $this->row(1)->name);
    }

    public function test_dates_and_booleans_are_shown_as_they_would_be_sent(): void
    {
        $queries = $this->batch()->pretend(function (Batch $batch) {
            $batch->update(new User, [['id' => 1, 'active' => false]]);
        });

        $this->assertContains(0, $queries[0]['bindings']);
        $this->assertContains(self::NOW, $queries[0]['bindings']);
        $this->assertSame([], array_filter($queries[0]['bindings'], 'is_bool'));
    }
}
