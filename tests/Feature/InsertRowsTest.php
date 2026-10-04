<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\Status;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;

class InsertRowsTest extends TestCase
{
    public function test_inserts_rows_given_as_column_value_pairs(): void
    {
        $result = $this->batch()->insertRows(new User, [
            ['id' => 1, 'code' => 'c1', 'name' => "it's"],
            ['name' => 'sara', 'id' => 2, 'code' => 'c2'], // different key order
        ]);

        $this->assertSame(['totalRows' => 2, 'totalBatch' => 500, 'totalQuery' => 1], $result);
        $this->assertSame("it's", $this->row(1)->name);
        $this->assertSame('sara', $this->row(2)->name);
        $this->assertSame('c2', $this->row(2)->code);
        $this->assertSame(self::NOW, (string) $this->row(2)->created_at);
    }

    public function test_batch_size_insert_ignore_enums_and_db_raw(): void
    {
        $rows = [];
        for ($i = 1; $i <= 150; $i++) {
            $rows[] = ['id' => $i, 'code' => "c{$i}", 'name' => Status::Active, 'balance' => DB::raw('6 * 7')];
        }

        $result = $this->batch()->insertRows(new User, $rows, 100);
        $this->batch()->insertRows(new User, [['id' => 1, 'code' => 'dup'], ['id' => 151, 'code' => 'c151']], 500, true);

        $this->assertSame(2, $result['totalQuery']);
        $this->assertSame(151, $this->countRows());
        $this->assertSame('c1', $this->row(1)->code);
        $this->assertSame('active', $this->row(150)->name);
        $this->assertEquals(42, $this->row(150)->balance);
    }

    public function test_rows_with_different_columns_throw(): void
    {
        foreach ([
            [['id' => 1, 'code' => 'c1'], ['id' => 2]],                          // missing column
            [['id' => 1, 'code' => 'c1'], ['id' => 2, 'code' => 'c2', 'x' => 1]], // extra column
            [['id' => 1, 'code' => 'c1'], ['id' => 2, 'name' => 'c2']],           // other column
        ] as $rows) {
            try {
                $this->batch()->insertRows(new User, $rows);
                $this->fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('same columns', $e->getMessage());
            }
        }

        $this->assertSame(0, $this->countRows());
    }

    public function test_rows_must_use_column_names_as_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->insertRows(new User, [[1, 'c1']]);
    }

    public function test_rows_must_be_non_empty_arrays(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->insertRows(new User, [['id' => 1], []]);
    }

    public function test_no_rows_inserts_nothing(): void
    {
        $this->assertSame(['totalRows' => 0, 'totalBatch' => 500, 'totalQuery' => 0], $this->batch()->insertRows(new User, []));
    }
}
