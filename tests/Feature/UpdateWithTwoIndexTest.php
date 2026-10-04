<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;

class UpdateWithTwoIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedUsers();
        DB::table('users')->where('id', 2)->update(['org' => 2]);
    }

    public function test_rows_must_match_both_indexes(): void
    {
        $affected = $this->batch()->updateWithTwoIndex(new User, [
            ['id' => 1, 'org' => 1, 'name' => 'match'],
            ['id' => 2, 'org' => 1, 'name' => 'wrong org'],
            ['id' => 3, 'org' => 1, 'phone' => '333'],
        ], 'id', 'org');

        $this->assertSame(2, $affected);
        $this->assertSame('match', $this->row(1)->name);
        $this->assertSame('name2', $this->row(2)->name);
        $this->assertSame('333', $this->row(3)->phone);
    }

    public function test_first_index_defaults_to_the_primary_key(): void
    {
        $this->batch()->updateWithTwoIndex(new User, [['id' => 2, 'org' => 2, 'name' => 'ali']], null, 'org');

        $this->assertSame('ali', $this->row(2)->name);
    }

    public function test_values_are_stored_literally(): void
    {
        $this->batch()->updateWithTwoIndex(new User, [
            ['id' => 1, 'org' => 1, 'name' => "o'reilly \"q\" \\", 'phone' => '0912', 'active' => false],
        ], 'id', 'org');

        $this->assertSame("o'reilly \"q\" \\", $this->row(1)->name);
        $this->assertSame('0912', $this->row(1)->phone);
        $this->assertFalse((bool) $this->row(1)->active);
    }

    public function test_arithmetic_raw_and_timestamps(): void
    {
        $this->batch()->updateWithTwoIndex(new User, [
            ['id' => 1, 'org' => 1, 'balance' => ['-', 40]],
            ['id' => 3, 'org' => 1, 'name' => 'name3'], // unchanged
        ], 'id', 'org');

        $this->assertEquals(60, $this->row(1)->balance);
        $this->assertSame(self::NOW, (string) $this->row(1)->updated_at);
        $this->assertSame(self::OLD, (string) $this->row(3)->updated_at);

        $this->batch()->updateWithTwoIndex(new User, [['id' => 2, 'org' => 2, 'balance' => 'balance + 1']], 'id', 'org', true);

        $this->assertEquals(101, $this->row(2)->balance);
    }

    public function test_second_index_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->updateWithTwoIndex(new User, [['id' => 1, 'name' => 'x']], 'id');
    }

    public function test_row_without_second_index_value_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->updateWithTwoIndex(new User, [['id' => 1, 'name' => 'x']], 'id', 'org');
    }

    public function test_returns_false_without_values(): void
    {
        $this->assertFalse($this->batch()->updateWithTwoIndex(new User, [], 'id', 'org'));
    }
}
