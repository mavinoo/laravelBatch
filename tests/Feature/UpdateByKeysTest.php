<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\SmallBatch;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;

class UpdateByKeysTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedUsers();
        DB::table('users')->where('id', 2)->update(['org' => 2]);
    }

    public function test_rows_must_match_every_key_column(): void
    {
        $this->batch()->updateByKeys(new User, [
            ['id' => 1, 'org' => 1, 'code' => 'c1', 'name' => 'match'],
            ['id' => 2, 'org' => 1, 'code' => 'c2', 'name' => 'wrong org'],
            ['id' => 3, 'org' => 1, 'code' => 'nope', 'name' => 'wrong code'],
        ], ['id', 'org', 'code']);

        $this->assertSame('match', $this->row(1)->name);
        $this->assertSame('name2', $this->row(2)->name);
        $this->assertSame('name3', $this->row(3)->name);
    }

    public function test_one_key_column_works_like_update(): void
    {
        $this->batch()->updateByKeys(new User, [['code' => 'c3', 'name' => 'three']], ['code']);

        $this->assertSame('three', $this->row(3)->name);
    }

    public function test_values_arithmetic_raw_and_timestamps(): void
    {
        $this->batch()->updateByKeys(new User, [
            ['id' => 1, 'org' => 1, 'name' => "o'reilly", 'balance' => ['+', 5]],
            ['id' => 3, 'org' => 1, 'balance' => DB::raw('balance * 2')],
            ['id' => 2, 'org' => 2, 'name' => 'name2'], // unchanged
        ], ['id', 'org']);

        $this->assertSame("o'reilly", $this->row(1)->name);
        $this->assertEquals(105, $this->row(1)->balance);
        $this->assertEquals(200, $this->row(3)->balance);
        $this->assertSame(self::NOW, (string) $this->row(1)->updated_at);
        $this->assertSame(self::OLD, (string) $this->row(2)->updated_at);
    }

    public function test_large_batches_are_split_into_several_queries(): void
    {
        DB::table('users')->delete();
        $this->seedUsers(30);

        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['id' => $i, 'code' => "c{$i}", 'name' => "new{$i}"];
        }

        $queries = $this->writeQueries(function () use ($rows) {
            $this->assertSame(30, (new SmallBatch($this->app['db']))->updateByKeys(new User, $rows, ['id', 'code']));
        });

        $this->assertGreaterThan(1, count($queries));
        $this->assertSame('new30', $this->row(30)->name);
    }

    public function test_a_failing_query_rolls_back_the_whole_batch(): void
    {
        DB::table('users')->delete();
        $this->seedUsers(30);

        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['id' => $i, 'org' => 1, 'code' => "new{$i}"];
        }
        $rows[29]['code'] = 'new1'; // unique violation in the last query

        try {
            (new SmallBatch($this->app['db']))->updateByKeys(new User, $rows, ['id', 'org']);
            $this->fail('Expected a unique constraint violation.');
        } catch (QueryException $e) {
            // expected
        }

        $this->assertSame('c1', $this->row(1)->code);
    }

    public function test_keys_are_required(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->updateByKeys(new User, [['id' => 1, 'name' => 'x']], []);
    }

    public function test_keys_must_be_column_names(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->updateByKeys(new User, [['id' => 1, 'name' => 'x']], ['id', 1]);
    }

    public function test_row_without_a_key_value_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->updateByKeys(new User, [['id' => 1, 'name' => 'x']], ['id', 'org']);
    }

    public function test_returns_zero_without_values(): void
    {
        $this->assertSame(0, $this->batch()->updateByKeys(new User, [], ['id']));
    }
}
