<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

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

    public function test_batch_update_with_index_and_raw(): void
    {
        $this->seedUsers();

        User::batchUpdate([['code' => 'c2', 'balance' => 'balance * 3']], 'code', true);

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
}
