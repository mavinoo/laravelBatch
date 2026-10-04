<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mavinoo\Batch\Batch;
use Mavinoo\Batch\BatchFacade;
use Mavinoo\Batch\Tests\Fixtures\SecondaryUser;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;

class IntegrationTest extends TestCase
{
    public function test_service_provider_binds_batch(): void
    {
        $this->assertInstanceOf(Batch::class, $this->app->make('Batch'));
    }

    public function test_facade_helper_and_container_share_one_instance(): void
    {
        $this->assertSame($this->app->make(Batch::class), $this->app->make('Batch'));
        $this->assertSame($this->app->make(Batch::class), batch());
        $this->assertSame($this->app->make(Batch::class), BatchFacade::getFacadeRoot());
    }

    public function test_facade_and_alias(): void
    {
        $this->assertInstanceOf(Batch::class, BatchFacade::getFacadeRoot());

        \Batch::insert(new User, ['id', 'code'], [[1, 'c1']]);
        BatchFacade::update(new User, [['id' => 1, 'name' => 'facade']]);

        $this->assertSame('facade', $this->row(1)->name);
    }

    public function test_helper(): void
    {
        $this->assertInstanceOf(Batch::class, batch());

        batch()->insert(new User, ['id', 'code'], [[1, 'c1']]);
        batch()->update(new User, [['id' => 1, 'name' => 'helper']]);

        $this->assertSame('helper', $this->row(1)->name);
    }

    public function test_table_prefix_is_applied(): void
    {
        $this->batch()->insert(new User, ['id', 'code'], [[1, 'c1']]);
        $this->batch()->update(new User, [['id' => 1, 'name' => 'prefixed']]);

        $this->assertSame('batch_', DB::connection()->getTablePrefix());
        $this->assertSame('prefixed', DB::connection()->selectOne('SELECT name FROM batch_users WHERE id = 1')->name);
    }

    public function test_models_on_another_connection(): void
    {
        $this->batch()->insert(new SecondaryUser, ['id', 'code'], [[1, 'c1'], [2, 'c2']]);
        $this->batch()->update(new SecondaryUser, [['id' => 1, 'name' => 'secondary']]);
        $this->batch()->updateWithTwoIndex(new SecondaryUser, [['id' => 2, 'org' => 1, 'name' => 'two']], 'id', 'org');
        $this->batch()->updateMultipleCondition(new SecondaryUser, [['conditions' => ['id' => 2], 'columns' => ['phone' => '2']]]);

        $this->assertSame(0, $this->countRows());
        $this->assertSame(2, $this->countRows('secondary'));
        $this->assertSame('secondary', $this->row(1, 'secondary')->name);
        $this->assertSame('two', $this->row(2, 'secondary')->name);
        $this->assertSame('2', $this->row(2, 'secondary')->phone);
    }

    public function test_rollback_happens_on_the_models_connection(): void
    {
        $rows = [];
        for ($i = 1; $i <= 150; $i++) {
            $rows[] = [$i, "c{$i}"];
        }
        $rows[140] = [1, 'duplicate'];

        try {
            $this->batch()->insert(new SecondaryUser, ['id', 'code'], $rows, 100);
            $this->fail('Expected a duplicate key error.');
        } catch (QueryException $e) {
            // expected
        }

        $this->assertSame(0, $this->countRows('secondary'));
    }
}
