<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\SecondaryUser;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;

class HasBatchTest extends TestCase
{
    public function test_batch_insert(): void
    {
        $result = User::batchInsert(['id', 'code'], [[1, 'c1'], [2, 'c2']]);

        $this->assertSame(['totalRows' => 2, 'totalBatch' => 500, 'totalQuery' => 1], $result);
        $this->assertSame(2, $this->countRows());
    }

    public function test_batch_insert_ignore(): void
    {
        User::batchInsert(['id', 'code'], [[1, 'c1']]);
        User::batchInsert(['id', 'code'], [[1, 'duplicate'], [2, 'c2']], 500, true);

        $this->assertSame('c1', $this->row(1)->code);
        $this->assertSame(2, $this->countRows());
    }

    public function test_batch_update(): void
    {
        $this->seedUsers();

        $affected = User::batchUpdate([
            ['id' => 1, 'name' => 'ali'],
            ['id' => 3, 'balance' => ['+', 1]],
        ]);

        $this->assertSame(2, $affected);
        $this->assertSame('ali', $this->row(1)->name);
        $this->assertEquals(101, $this->row(3)->balance);
    }

    public function test_batch_update_with_index_and_db_raw(): void
    {
        $this->seedUsers();

        User::batchUpdate([['code' => 'c2', 'balance' => DB::raw('balance * 3')]], 'code');

        $this->assertEquals(300, $this->row(2)->balance);
    }

    public function test_update_multiple_condition(): void
    {
        $this->seedUsers();

        (new User)->updateMultipleCondition([
            ['conditions' => ['id' => 1, 'name' => 'name1'], 'columns' => ['name' => 'ali']],
            ['conditions' => ['id' => 2, 'name' => 'nope'], 'columns' => ['name' => 'skipped']],
        ], 'id');

        $this->assertSame('ali', $this->row(1)->name);
        $this->assertSame('name2', $this->row(2)->name);
    }

    public function test_the_removed_raw_flag_fails_loudly(): void
    {
        $this->seedUsers();

        $this->expectException(InvalidArgumentException::class);

        User::batchUpdate([['id' => 1, 'balance' => 'balance * 3']], 'id', true);
    }

    public function test_static_update_multiple_condition(): void
    {
        $this->seedUsers();

        User::batchUpdateMultipleCondition([
            ['conditions' => ['id' => 1, 'name' => 'name1'], 'columns' => ['name' => 'static']],
        ], 'id');

        $this->assertSame('static', $this->row(1)->name);
    }

    public function test_static_update_with_two_index_and_by_keys(): void
    {
        $this->seedUsers();

        User::batchUpdateWithTwoIndex([['id' => 1, 'org' => 1, 'name' => 'two']], 'id', 'org');
        User::batchUpdateByKeys([['id' => 2, 'code' => 'c2', 'name' => 'keys']], ['id', 'code']);

        $this->assertSame('two', $this->row(1)->name);
        $this->assertSame('keys', $this->row(2)->name);
    }

    public function test_static_insert_rows_and_upsert(): void
    {
        $result = User::batchInsertRows([['code' => 'c1'], ['code' => 'c2']]);
        User::batchUpsert([['code' => 'c2', 'name' => 'upserted'], ['code' => 'c3', 'name' => 'new']], ['code'], ['name']);

        $this->assertSame(2, $result['totalRows']);
        $this->assertSame(3, $this->countRows());
        $this->assertSame('upserted', $this->row(2)->name);
    }

    public function test_static_methods_use_the_calling_model(): void
    {
        SecondaryUser::batchInsertRows([['id' => 1, 'code' => 'c1']]);
        SecondaryUser::batchUpdate([['id' => 1, 'name' => 'secondary']]);

        $this->assertSame(0, $this->countRows());
        $this->assertSame('secondary', $this->row(1, 'secondary')->name);
    }
}
