<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;

class UpdateMultipleConditionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedUsers();
    }

    public function test_rows_must_match_every_condition(): void
    {
        $this->batch()->updateMultipleCondition(new User, [
            ['conditions' => ['id' => 1, 'name' => 'name1'], 'columns' => ['name' => 'match', 'phone' => '111']],
            ['conditions' => ['id' => 2, 'name' => 'other'], 'columns' => ['name' => 'skipped']],
            ['conditions' => ['id' => 3], 'columns' => ['phone' => '333']],
        ], 'id');

        $this->assertSame('match', $this->row(1)->name);
        $this->assertSame('111', $this->row(1)->phone);
        $this->assertSame('name2', $this->row(2)->name);
        $this->assertSame('333', $this->row(3)->phone);
    }

    public function test_key_defaults_to_the_primary_key(): void
    {
        $this->batch()->updateMultipleCondition(new User, [
            ['conditions' => ['id' => 2], 'columns' => ['name' => 'ali']],
        ]);

        $this->assertSame('ali', $this->row(2)->name);
    }

    public function test_string_key_values(): void
    {
        $this->batch()->updateMultipleCondition(new User, [
            ['conditions' => ['code' => 'c1'], 'columns' => ['name' => 'one']],
            ['conditions' => ['code' => 'c3'], 'columns' => ['name' => 'three']],
        ], 'code');

        $this->assertSame('one', $this->row(1)->name);
        $this->assertSame('three', $this->row(3)->name);
    }

    public function test_null_condition_matches_null_columns(): void
    {
        DB::table('users')->where('id', 2)->update(['phone' => '222']);

        $this->batch()->updateMultipleCondition(new User, [
            ['conditions' => ['id' => 1, 'phone' => null], 'columns' => ['name' => 'was null']],
            ['conditions' => ['id' => 2, 'phone' => null], 'columns' => ['name' => 'skipped']],
        ]);

        $this->assertSame('was null', $this->row(1)->name);
        $this->assertSame('name2', $this->row(2)->name);
    }

    public function test_values_and_conditions_are_used_literally(): void
    {
        DB::table('users')->where('id', 1)->update(['name' => "it's"]);

        $this->batch()->updateMultipleCondition(new User, [
            ['conditions' => ['code' => 'c1', 'name' => "it's"], 'columns' => ['name' => "o'reilly", 'phone' => '0912']],
            ['conditions' => ['code' => "c2' OR '1'='1"], 'columns' => ['name' => 'hacked']],
        ], 'code');

        $this->assertSame("o'reilly", $this->row(1)->name);
        $this->assertSame('0912', $this->row(1)->phone);
        $this->assertSame(0, DB::table('users')->where('name', 'hacked')->count());
    }

    public function test_arithmetic_null_and_raw_columns(): void
    {
        $this->batch()->updateMultipleCondition(new User, [
            ['conditions' => ['id' => 1], 'columns' => ['balance' => ['+', 10], 'name' => null]],
        ]);
        $this->batch()->updateMultipleCondition(new User, [
            ['conditions' => ['id' => 2], 'columns' => ['balance' => DB::raw('balance - 1')]],
        ], 'id');

        $this->assertEquals(110, $this->row(1)->balance);
        $this->assertNull($this->row(1)->name);
        $this->assertEquals(99, $this->row(2)->balance);
    }

    public function test_updated_at_only_changes_for_rows_that_change(): void
    {
        $this->batch()->updateMultipleCondition(new User, [
            ['conditions' => ['id' => 1], 'columns' => ['name' => 'name1']],
            ['conditions' => ['id' => 2], 'columns' => ['name' => 'changed']],
            ['conditions' => ['id' => 3], 'columns' => ['name' => 'z', 'updated_at' => '2022-02-02 00:00:00']],
        ]);

        $this->assertSame(self::OLD, (string) $this->row(1)->updated_at);
        $this->assertSame(self::NOW, (string) $this->row(2)->updated_at);
        $this->assertSame('2022-02-02 00:00:00', (string) $this->row(3)->updated_at);
    }

    public function test_a_condition_column_can_be_updated_too(): void
    {
        // The README example: match on status and change it, along with other columns.
        DB::table('users')->update(['phone' => 'active']);

        $this->batch()->updateMultipleCondition(new User, [
            ['conditions' => ['id' => 1, 'phone' => 'active'], 'columns' => ['phone' => 'invalid', 'name' => 'mohammad', 'balance' => ['+', 1]]],
            ['conditions' => ['id' => 2, 'phone' => 'active'], 'columns' => ['name' => 'mavinoo']],
        ]);

        $this->assertSame('invalid', $this->row(1)->phone);
        $this->assertSame('mohammad', $this->row(1)->name);
        $this->assertEquals(101, $this->row(1)->balance);
        $this->assertSame(self::NOW, (string) $this->row(1)->updated_at);
        $this->assertSame('mavinoo', $this->row(2)->name);
    }

    public function test_condition_columns_that_depend_on_each_other(): void
    {
        $update = function () {
            $this->batch()->updateMultipleCondition(new User, [
                ['conditions' => ['id' => 1, 'name' => 'name1'], 'columns' => ['phone' => '111']],
                ['conditions' => ['id' => 2, 'phone' => null], 'columns' => ['name' => 'two']],
                ['conditions' => ['id' => 3, 'name' => 'name3', 'phone' => null], 'columns' => ['name' => 'three', 'phone' => '333']],
            ]);
        };

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            // MySQL assigns columns one after another, so there is no safe order.
            $this->expectException(InvalidArgumentException::class);
            $update();

            return;
        }

        $update();

        $this->assertSame('111', $this->row(1)->phone);
        $this->assertSame('two', $this->row(2)->name);
        $this->assertSame('three', $this->row(3)->name);
        $this->assertSame('333', $this->row(3)->phone);
    }

    public function test_item_without_conditions_or_columns_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->updateMultipleCondition(new User, [['columns' => ['name' => 'x']]]);
    }

    public function test_item_without_key_condition_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->updateMultipleCondition(new User, [
            ['conditions' => ['name' => 'name1'], 'columns' => ['name' => 'x']],
        ], 'id');
    }

    public function test_returns_zero_without_values(): void
    {
        $this->assertSame(0, $this->batch()->updateMultipleCondition(new User, []));
    }

    public function test_db_raw_in_conditions(): void
    {
        $this->batch()->updateMultipleCondition(new User, [
            ['conditions' => ['id' => 3, 'balance' => DB::raw('50 + 50')], 'columns' => ['name' => 'matched']],
        ]);

        $this->assertSame('matched', $this->row(3)->name);
    }

    public function test_the_removed_raw_flag_fails_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->updateMultipleCondition(new User, [['conditions' => ['id' => 1], 'columns' => ['name' => 'x']]], 'id', true);
    }
}
