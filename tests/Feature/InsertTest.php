<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\NoCreatedAtUser;
use Mavinoo\Batch\Tests\Fixtures\NoUpdatedAtUser;
use Mavinoo\Batch\Tests\Fixtures\Size;
use Mavinoo\Batch\Tests\Fixtures\SmallBatch;
use Mavinoo\Batch\Tests\Fixtures\Status;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\Fixtures\UserWithoutTimestamps;
use Mavinoo\Batch\Tests\TestCase;

class InsertTest extends TestCase
{
    private function rows(int $from, int $to): array
    {
        $rows = [];
        for ($i = $from; $i <= $to; $i++) {
            $rows[] = [$i, "c{$i}"];
        }

        return $rows;
    }

    public function test_inserts_rows_and_reports_counts(): void
    {
        $result = $this->batch()->insert(new User, ['id', 'code', 'name'], [
            [1, 'c1', 'ali'],
            [2, 'c2', 'sara'],
            [3, 'c3', null],
        ]);

        $this->assertSame(['totalRows' => 3, 'totalBatch' => 500, 'totalQuery' => 1], $result);
        $this->assertSame(3, $this->countRows());
        $this->assertSame('sara', $this->row(2)->name);
        $this->assertNull($this->row(3)->name);
    }

    public function test_fills_timestamps(): void
    {
        $this->batch()->insert(new User, ['id', 'code'], [[1, 'c1']]);

        $this->assertSame(self::NOW, (string) $this->row(1)->created_at);
        $this->assertSame(self::NOW, (string) $this->row(1)->updated_at);
    }

    public function test_keeps_given_timestamps(): void
    {
        $this->batch()->insert(new User, ['id', 'created_at'], [[1, self::OLD]]);

        $this->assertSame(self::OLD, (string) $this->row(1)->created_at);
        $this->assertSame(self::NOW, (string) $this->row(1)->updated_at);
    }

    public function test_model_without_timestamps_leaves_them_empty(): void
    {
        $this->batch()->insert(new UserWithoutTimestamps, ['id', 'code'], [[1, 'c1']]);

        $this->assertNull($this->row(1)->created_at);
        $this->assertNull($this->row(1)->updated_at);
    }

    public function test_values_are_stored_literally(): void
    {
        $json = json_encode(["a'b" => "he said \"hi\"\nok", 'path' => 'C:\\temp\\']);

        $this->batch()->insert(new User, ['id', 'name', 'phone', 'meta', 'active'], [
            [1, "x' OR 1=1 --", '09121234567', $json, false],
            [2, 'ünïcødé ✓ فارسی', '0', '{}', true],
        ]);

        $this->assertSame("x' OR 1=1 --", $this->row(1)->name);
        $this->assertSame('09121234567', $this->row(1)->phone);
        $this->assertSame($json, $this->row(1)->meta);
        $this->assertFalse((bool) $this->row(1)->active);
        $this->assertSame('ünïcødé ✓ فارسی', $this->row(2)->name);
        $this->assertSame('0', $this->row(2)->phone);
        $this->assertTrue((bool) $this->row(2)->active);
    }

    public function test_splits_rows_by_batch_size(): void
    {
        $result = $this->batch()->insert(new User, ['id', 'code'], $this->rows(1, 250), 100);

        $this->assertSame(['totalRows' => 250, 'totalBatch' => 100, 'totalQuery' => 3], $result);
        $this->assertSame(250, $this->countRows());
    }

    public function test_batch_size_below_one_hundred_is_raised_to_one_hundred(): void
    {
        $result = $this->batch()->insert(new User, ['id', 'code'], $this->rows(1, 150), 10);

        $this->assertSame(['totalRows' => 150, 'totalBatch' => 100, 'totalQuery' => 2], $result);
        $this->assertSame(150, $this->countRows());
    }

    public function test_splits_rows_by_the_bound_parameter_limit(): void
    {
        // 4 parameters per row (id, code, created_at, updated_at), limit 40 -> 10 rows per query.
        $result = (new SmallBatch($this->app['db']))->insert(new User, ['id', 'code'], $this->rows(1, 25));

        $this->assertSame(3, $result['totalQuery']);
        $this->assertSame(25, $this->countRows());
    }

    public function test_a_row_with_the_wrong_column_count_throws(): void
    {
        foreach ([[[1]], [[1, 'c1'], [2, 'c2', 'extra']], [['not-an-array']]] as $values) {
            try {
                $this->batch()->insert(new User, ['id', 'code'], $values);
                $this->fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('columns', $e->getMessage());
            }
        }

        $this->assertSame(0, $this->countRows());
    }

    public function test_rows_without_columns_throw(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->insert(new User, [], [[1]]);
    }

    public function test_no_rows_inserts_nothing(): void
    {
        $queries = $this->writeQueries(function () {
            $this->assertSame(['totalRows' => 0, 'totalBatch' => 500, 'totalQuery' => 0], $this->batch()->insert(new User, ['id'], []));
        });

        $this->assertCount(0, $queries);
    }

    public function test_enum_values(): void
    {
        $this->batch()->insert(new User, ['id', 'name', 'phone'], [[1, Status::Active, Size::Small]]);

        $this->assertSame('active', $this->row(1)->name);
        $this->assertSame('Small', $this->row(1)->phone);
    }

    public function test_db_raw_values(): void
    {
        $this->batch()->insert(new User, ['id', 'code', 'balance'], [[1, 'c1', DB::raw('6 * 7')]]);

        $this->assertEquals(42, $this->row(1)->balance);
    }

    public function test_models_without_one_timestamp_column(): void
    {
        // https://github.com/mavinoo/laravelBatch/issues/107
        $this->batch()->insert(new NoUpdatedAtUser, ['id', 'code'], [[1, 'c1']]);
        $this->batch()->insert(new NoCreatedAtUser, ['id', 'code'], [[2, 'c2']]);
        $this->batch()->update(new NoUpdatedAtUser, [['id' => 1, 'name' => 'x']]);

        $this->assertSame(self::NOW, (string) $this->row(1)->created_at);
        $this->assertNull($this->row(1)->updated_at);
        $this->assertSame('x', $this->row(1)->name);
        $this->assertNull($this->row(2)->created_at);
        $this->assertSame(self::NOW, (string) $this->row(2)->updated_at);
    }

    public function test_insert_ignore_skips_duplicates(): void
    {
        $this->batch()->insert(new User, ['id', 'code'], [[1, 'c1']]);

        $this->batch()->insert(new User, ['id', 'code'], [[1, 'duplicate'], [2, 'c2']], 500, true);

        $this->assertSame('c1', $this->row(1)->code);
        $this->assertSame('c2', $this->row(2)->code);
    }

    public function test_duplicate_without_ignore_throws(): void
    {
        $this->batch()->insert(new User, ['id', 'code'], [[1, 'c1']]);

        $this->expectException(QueryException::class);

        $this->batch()->insert(new User, ['id', 'code'], [[1, 'duplicate']]);
    }

    public function test_a_failing_query_rolls_back_the_whole_batch(): void
    {
        $rows = $this->rows(1, 150);
        $rows[140] = [1, 'c-duplicate']; // duplicate primary key in the second query

        try {
            $this->batch()->insert(new User, ['id', 'code'], $rows, 100);
            $this->fail('Expected a duplicate key error.');
        } catch (QueryException $e) {
            // expected
        }

        $this->assertSame(0, $this->countRows());
    }
}
