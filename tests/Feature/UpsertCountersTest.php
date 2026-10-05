<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;

class UpsertCountersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedUsers(3); // balance 100 each
    }

    private function balance(string $code): int
    {
        return (int) DB::table('users')->where('code', $code)->value('balance');
    }

    public function test_adds_to_the_stored_value_and_inserts_new_rows(): void
    {
        $this->batch()->upsert(new User, [
            ['code' => 'c1', 'balance' => 5],
            ['code' => 'c9', 'balance' => 7],
        ], ['code'], ['balance' => ['+']]);

        $this->assertSame(105, $this->balance('c1'));
        $this->assertSame(7, $this->balance('c9'));
        $this->assertSame(100, $this->balance('c2'));
        $this->assertSame(self::NOW, (string) $this->row(1)->updated_at);
    }

    public function test_subtract_max_and_min(): void
    {
        $this->batch()->upsert(new User, [['code' => 'c1', 'balance' => 30]], 'code', ['balance' => ['-']]);
        $this->batch()->upsert(new User, [['code' => 'c2', 'balance' => 150], ['code' => 'c3', 'balance' => 50]], 'code', ['balance' => ['max']]);
        $this->batch()->upsert(new User, [['code' => 'c3', 'balance' => 20]], 'code', ['balance' => ['min']]);

        $this->assertSame(70, $this->balance('c1'));
        $this->assertSame(150, $this->balance('c2'));
        $this->assertSame(20, $this->balance('c3'));
    }

    public function test_counters_mix_with_plain_columns_and_add_up_across_chunks(): void
    {
        $rows = [];
        for ($i = 0; $i < 300; $i++) {
            $rows[] = ['code' => 'c' . (1 + $i % 3), 'name' => "n{$i}", 'balance' => 1];
        }

        // Each statement holds every code once at most, so split the rows by statement first.
        foreach (array_chunk($rows, 3) as $chunk) {
            $this->batch()->upsert(new User, $chunk, ['code'], ['name', 'balance' => ['+']]);
        }

        $this->assertSame(200, $this->balance('c1'));
        $this->assertSame('n299', DB::table('users')->where('code', 'c3')->value('name'));
    }

    public function test_only_if_updates_rows_whose_new_value_is_newer(): void
    {
        DB::table('users')->where('code', 'c2')->update(['updated_at' => null]);

        $this->batch()->upsert(new User, [
            ['code' => 'c1', 'name' => 'stale', 'updated_at' => '2019-06-01 00:00:00'], // older: kept
            ['code' => 'c2', 'name' => 'no date', 'updated_at' => '2019-06-01 00:00:00'], // stored null: updated
            ['code' => 'c3', 'name' => 'fresh', 'updated_at' => '2021-06-01 00:00:00'], // newer: updated
            ['code' => 'c9', 'name' => 'new', 'updated_at' => '2018-01-01 00:00:00'],   // new: inserted
        ], ['code'], ['name', 'updated_at'], ['onlyIf' => ['updated_at' => '>']]);

        $this->assertSame('name1', $this->row(1)->name);
        $this->assertSame(self::OLD, (string) $this->row(1)->updated_at);
        $this->assertSame('no date', $this->row(2)->name);
        $this->assertSame('fresh', $this->row(3)->name);
        $this->assertSame('2021-06-01 00:00:00', (string) $this->row(3)->updated_at);
        $this->assertSame('new', DB::table('users')->where('code', 'c9')->value('name'));
    }

    public function test_only_if_on_another_column_with_a_counter(): void
    {
        DB::table('users')->where('code', 'c1')->update(['org' => 5]);

        $this->batch()->upsert(new User, [
            ['code' => 'c1', 'org' => 3, 'balance' => 10], // org not greater: nothing changes
            ['code' => 'c2', 'org' => 3, 'balance' => 10], // org 1 < 3: updated
        ], ['code'], ['org', 'balance' => ['+']], ['onlyIf' => ['org' => '>']]);

        $this->assertSame(100, $this->balance('c1'));
        $this->assertSame(5, (int) $this->row(1)->org);
        $this->assertSame(self::OLD, (string) $this->row(1)->updated_at);
        $this->assertSame(110, $this->balance('c2'));
        $this->assertSame(3, (int) $this->row(2)->org);
    }

    public function test_pretend_shows_the_expressions_with_bound_values(): void
    {
        $queries = $this->batch()->pretend(function ($batch) {
            $batch->upsert(new User, [['code' => "x'); --", 'balance' => 5]], ['code'], ['balance' => ['+']]);
        });

        $this->assertStringContainsString('coalesce(', strtolower($queries[0]['sql']));
        $this->assertStringNotContainsString("x');", $queries[0]['sql']);
        $this->assertContains("x'); --", $queries[0]['bindings']);
    }

    public function test_invalid_counters_and_conditions_throw(): void
    {
        $cases = [
            'arithmetic form' => fn () => $this->batch()->upsert(new User, [['code' => 'c1', 'balance' => 1]], 'code', ['balance' => ['+', 5]]),
            'unknown counter' => fn () => $this->batch()->upsert(new User, [['code' => 'c1', 'balance' => 1]], 'code', ['balance' => ['*']]),
            'counter column missing' => fn () => $this->batch()->upsert(new User, [['code' => 'c1', 'name' => 'x']], 'code', ['balance' => ['+']]),
            'bad operator' => fn () => $this->batch()->upsert(new User, [['code' => 'c1', 'org' => 1]], 'code', null, ['onlyIf' => ['org' => '=>']]),
            'condition column missing' => fn () => $this->batch()->upsert(new User, [['code' => 'c1', 'name' => 'x']], 'code', null, ['onlyIf' => ['org' => '>']]),
            'unknown option' => fn () => $this->batch()->upsert(new User, [['code' => 'c1']], 'code', null, ['only_if' => []]),
        ];

        foreach ($cases as $name => $case) {
            try {
                $case();
                $this->fail("{$name}: expected an exception.");
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(100, $this->balance('c1'));
    }

    public function test_mysql_cannot_update_two_condition_columns(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // Other databases read every condition before any assignment.
            $this->batch()->upsert(new User, [['code' => 'c1', 'org' => 2, 'balance' => 200]], 'code', null, ['onlyIf' => ['org' => '>', 'balance' => '>']]);
            $this->assertSame(200, $this->balance('c1'));

            return;
        }

        $this->expectException(InvalidArgumentException::class);

        $this->batch()->upsert(new User, [['code' => 'c1', 'org' => 2, 'balance' => 200]], 'code', null, ['onlyIf' => ['org' => '>', 'balance' => '>']]);
    }
}
