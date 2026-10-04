<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\Size;
use Mavinoo\Batch\Tests\Fixtures\SmallBatch;
use Mavinoo\Batch\Tests\Fixtures\Status;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\Fixtures\UserWithoutTimestamps;
use Mavinoo\Batch\Tests\TestCase;

class UpdateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedUsers();
    }

    public function test_updates_each_row_with_its_own_columns(): void
    {
        $affected = $this->batch()->update(new User, [
            ['id' => 1, 'name' => 'ali', 'phone' => '111'],
            ['id' => 2, 'phone' => '222'],
        ], 'id');

        $this->assertSame(2, $affected);
        $this->assertSame('ali', $this->row(1)->name);
        $this->assertSame('111', $this->row(1)->phone);
        $this->assertSame('name2', $this->row(2)->name);
        $this->assertSame('222', $this->row(2)->phone);
        $this->assertSame('name3', $this->row(3)->name);
        $this->assertNull($this->row(3)->phone);
    }

    public function test_index_defaults_to_the_primary_key(): void
    {
        $this->batch()->update(new User, [['id' => 2, 'name' => 'ali']]);

        $this->assertSame('ali', $this->row(2)->name);
    }

    public function test_matches_rows_on_a_custom_index(): void
    {
        $this->batch()->update(new User, [
            ['code' => 'c1', 'name' => 'first'],
            ['code' => 'c3', 'name' => 'third'],
        ], 'code');

        $this->assertSame('first', $this->row(1)->name);
        $this->assertSame('name2', $this->row(2)->name);
        $this->assertSame('third', $this->row(3)->name);
    }

    public function test_returns_zero_without_values(): void
    {
        $this->assertSame(0, $this->batch()->update(new User, []));
    }

    public function test_returns_zero_when_rows_only_contain_the_index(): void
    {
        $queries = $this->writeQueries(function () {
            $this->assertSame(0, $this->batch()->update(new User, [['id' => 1], ['id' => 2]]));
        });

        $this->assertCount(0, $queries);
    }

    public function test_row_without_index_value_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->update(new User, [['id' => 1, 'name' => 'a'], ['name' => 'b']]);
    }

    public function test_row_that_is_not_an_array_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->update(new User, [['id' => 1, 'name' => 'a'], 'oops']);
    }

    public function test_array_as_index_value_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->update(new User, [['id' => [1, 2], 'name' => 'a']]);
    }

    public function test_enum_values_are_stored_by_value(): void
    {
        DB::table('users')->where('id', 2)->update(['name' => 'active']);

        $this->batch()->update(new User, [
            ['id' => 1, 'name' => Status::Blocked, 'phone' => Size::Large],
            ['id' => 2, 'name' => Status::Active], // unchanged
        ]);

        $this->assertSame('blocked', $this->row(1)->name);
        $this->assertSame('Large', $this->row(1)->phone);
        $this->assertSame(self::NOW, (string) $this->row(1)->updated_at);
        $this->assertSame(self::OLD, (string) $this->row(2)->updated_at);
    }

    public function test_duplicate_index_rows_apply_the_first_one(): void
    {
        $this->batch()->update(new User, [
            ['id' => 1, 'name' => 'first'],
            ['id' => 1, 'name' => 'second'],
        ]);

        $this->assertSame('first', $this->row(1)->name);
    }

    public function test_string_values_are_stored_literally(): void
    {
        $values = [
            "x' OR 1=1 --",
            'back\\slash \\\' mix',
            'double "quotes"',
            "new\nline\ttab",
            '09121234567',
            'ünïcødé ✓ فارسی',
            '',
            '0',
        ];
        DB::table('users')->delete();
        $this->seedUsers(count($values));

        $rows = [];
        foreach ($values as $i => $value) {
            $rows[] = ['id' => $i + 1, 'name' => $value];
        }
        $this->batch()->update(new User, $rows);

        foreach ($values as $i => $value) {
            $this->assertSame($value, $this->row($i + 1)->name);
        }
    }

    public function test_quote_in_index_value_matches_nothing(): void
    {
        $affected = $this->batch()->update(new User, [['code' => "c1' OR '1'='1", 'name' => 'hacked']], 'code');

        $this->assertSame(0, $affected);
        $this->assertSame(0, DB::table('users')->where('name', 'hacked')->count());
    }

    public function test_json_values_round_trip(): void
    {
        $json = json_encode([
            "it's" => "he said \"hi\"\nthen left",
            'path' => 'C:\\temp\\',
            'nested' => ['ok' => true, 'n' => 1.5],
        ]);

        $this->batch()->update(new User, [['id' => 1, 'meta' => $json], ['id' => 2, 'meta' => '{}']]);

        $this->assertSame($json, $this->row(1)->meta);
        $this->assertSame('{}', $this->row(2)->meta);
    }

    public function test_null_boolean_and_date_values(): void
    {
        $this->batch()->update(new User, [
            ['id' => 1, 'name' => null, 'active' => false],
            ['id' => 2, 'created_at' => Carbon::parse('2021-05-06 07:08:09')],
        ]);

        $this->assertNull($this->row(1)->name);
        $this->assertFalse((bool) $this->row(1)->active);
        $this->assertSame('2021-05-06 07:08:09', (string) $this->row(2)->created_at);
    }

    public function test_arithmetic_operators(): void
    {
        DB::table('users')->delete();
        $this->seedUsers(6);

        $this->batch()->update(new User, [
            ['id' => 1, 'balance' => ['+', 5]],
            ['id' => 2, 'balance' => ['-', 30]],
            ['id' => 3, 'balance' => ['-', -5]],
            ['id' => 4, 'balance' => ['*', 2.5]],
            ['id' => 5, 'balance' => ['/', 4]],
            ['id' => 6, 'balance' => ['%', 7]],
        ]);

        $this->assertEquals(105, $this->row(1)->balance);
        $this->assertEquals(70, $this->row(2)->balance);
        $this->assertEquals(105, $this->row(3)->balance);
        $this->assertEquals(250, $this->row(4)->balance);
        $this->assertEquals(25, $this->row(5)->balance);
        $this->assertEquals(2, $this->row(6)->balance);
    }

    public function test_arithmetic_needs_two_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->update(new User, [['id' => 1, 'balance' => ['+']]]);
    }

    public function test_arithmetic_needs_a_known_operator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->update(new User, [['id' => 1, 'balance' => ['^', 2]]]);
    }

    public function test_arithmetic_needs_a_numeric_operand(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->update(new User, [['id' => 1, 'balance' => ['+', '1; DROP TABLE users']]]);
    }

    public function test_db_raw_values_are_used_as_sql(): void
    {
        $this->batch()->update(new User, [
            ['id' => 1, 'balance' => DB::raw('balance * 2'), 'name' => 'raw'],
            ['id' => 2, 'balance' => DB::raw('3 * 4')],
            ['id' => 3, 'name' => 'balance * 2'], // a plain string stays a string
        ]);

        $this->assertEquals(200, $this->row(1)->balance);
        $this->assertSame('raw', $this->row(1)->name);
        $this->assertEquals(12, $this->row(2)->balance);
        $this->assertSame('balance * 2', $this->row(3)->name);
        $this->assertSame(self::NOW, (string) $this->row(2)->updated_at);
    }

    public function test_db_raw_in_the_index_value(): void
    {
        $this->batch()->update(new User, [['id' => DB::raw('1 + 1'), 'name' => 'two']]);

        $this->assertSame('two', $this->row(2)->name);
    }

    public function test_the_removed_raw_flag_fails_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DB::raw()');

        $this->batch()->update(new User, [['id' => 1, 'balance' => 'balance * 2']], 'id', true);
    }

    public function test_a_false_raw_flag_is_ignored(): void
    {
        $this->batch()->update(new User, [['id' => 1, 'name' => 'x']], 'id', false);

        $this->assertSame('x', $this->row(1)->name);
    }

    public function test_updated_at_only_changes_for_rows_that_change(): void
    {
        $this->batch()->update(new User, [
            ['id' => 1, 'name' => 'name1'],   // same value
            ['id' => 2, 'name' => 'changed'],
            ['id' => 3, 'phone' => '123'],    // NULL -> value
        ]);

        $this->assertSame(self::OLD, (string) $this->row(1)->updated_at);
        $this->assertSame(self::NOW, (string) $this->row(2)->updated_at);
        $this->assertSame(self::NOW, (string) $this->row(3)->updated_at);
    }

    public function test_updated_at_changes_when_a_value_becomes_null(): void
    {
        DB::table('users')->where('id', 1)->update(['phone' => '123']);

        $this->batch()->update(new User, [
            ['id' => 1, 'phone' => null],
            ['id' => 2, 'phone' => null], // already NULL
        ]);

        $this->assertNull($this->row(1)->phone);
        $this->assertSame(self::NOW, (string) $this->row(1)->updated_at);
        $this->assertSame(self::OLD, (string) $this->row(2)->updated_at);
    }

    public function test_arithmetic_changes_updated_at(): void
    {
        $this->batch()->update(new User, [['id' => 1, 'balance' => ['+', 1]]]);

        $this->assertSame(self::NOW, (string) $this->row(1)->updated_at);
    }

    public function test_explicit_updated_at_is_kept_next_to_automatic_ones(): void
    {
        $this->batch()->update(new User, [
            ['id' => 1, 'name' => 'x', 'updated_at' => '2022-02-02 00:00:00'],
            ['id' => 2, 'name' => 'y'],
        ]);

        $this->assertSame('2022-02-02 00:00:00', (string) $this->row(1)->updated_at);
        $this->assertSame(self::NOW, (string) $this->row(2)->updated_at);
    }

    public function test_model_without_timestamps_leaves_updated_at_alone(): void
    {
        $this->batch()->update(new UserWithoutTimestamps, [['id' => 1, 'name' => 'x']]);

        $this->assertSame('x', $this->row(1)->name);
        $this->assertSame(self::OLD, (string) $this->row(1)->updated_at);
    }

    public function test_large_batches_are_split_into_several_queries(): void
    {
        DB::table('users')->delete();
        $this->seedUsers(30);

        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['id' => $i, 'name' => "new{$i}"];
        }

        $affected = null;
        $queries = $this->writeQueries(function () use ($rows, &$affected) {
            $affected = (new SmallBatch($this->app['db']))->update(new User, $rows);
        });

        $this->assertGreaterThan(1, count($queries));
        $this->assertSame(30, $affected);
        for ($i = 1; $i <= 30; $i++) {
            $this->assertSame("new{$i}", $this->row($i)->name);
        }
    }

    public function test_update_statements_are_capped_at_one_hundred_rows(): void
    {
        $rows = [];
        for ($i = 1; $i <= 250; $i++) {
            $rows[] = ['id' => $i, 'name' => "n{$i}"];
        }

        $queries = $this->batch()->pretend(fn ($batch) => $batch->update(new User, $rows));

        $this->assertCount(3, $queries);
    }

    public function test_a_failing_query_rolls_back_the_whole_batch(): void
    {
        DB::table('users')->delete();
        $this->seedUsers(30);

        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['id' => $i, 'code' => "new{$i}"];
        }
        $rows[29]['code'] = 'new1'; // unique violation in the last query

        try {
            (new SmallBatch($this->app['db']))->update(new User, $rows);
            $this->fail('Expected a unique constraint violation.');
        } catch (QueryException $e) {
            // expected
        }

        $this->assertSame('c1', $this->row(1)->code);
    }
}
