<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\CastingUser;
use Mavinoo\Batch\Tests\Fixtures\SecondaryUser;
use Mavinoo\Batch\Tests\Fixtures\SmallBatch;
use Mavinoo\Batch\Tests\Fixtures\Status;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;

class InsertGetIdsTest extends TestCase
{
    /**
     * Assert that each id points at the row with the expected code.
     *
     * @param list<int> $ids
     * @param list<string> $codes
     */
    private function assertIdsMatch(array $ids, array $codes, string $connection = 'testing'): void
    {
        $this->assertCount(count($codes), $ids);
        $this->assertSame(array_values(array_unique($ids)), $ids);

        $stored = DB::connection($connection)->table('users')->whereIn('id', $ids)->pluck('code', 'id')->all();
        foreach ($ids as $i => $id) {
            $this->assertIsInt($id);
            $this->assertSame($codes[$i], $stored[$id] ?? null, "id {$id} should belong to {$codes[$i]}");
        }
    }

    public function test_returns_the_new_ids_in_row_order(): void
    {
        $this->seedUsers(3);

        $ids = $this->batch()->insertGetIds(new User, [
            ['code' => 'a', 'name' => 'Ali'],
            ['code' => 'b', 'name' => 'Sara'],
            ['name' => 'Reza', 'code' => 'c'], // key order doesn't matter
        ]);

        $this->assertSame([4, 5, 6], $ids);
        $this->assertIdsMatch($ids, ['a', 'b', 'c']);
        $this->assertSame(self::NOW, (string) $this->row(4)->created_at);
        $this->assertSame(self::NOW, (string) $this->row(6)->updated_at);
    }

    public function test_returns_an_empty_list_without_rows(): void
    {
        $this->assertSame([], $this->batch()->insertGetIds(new User, []));
    }

    public function test_ids_are_right_across_several_queries(): void
    {
        $rows = [];
        $codes = [];
        for ($i = 1; $i <= 250; $i++) {
            $rows[] = ['code' => "r{$i}", 'name' => "n{$i}"];
            $codes[] = "r{$i}";
        }

        $queries = $this->writeQueries(function () use ($rows, &$ids) {
            $ids = $this->batch()->insertGetIds(new User, $rows, 100);
        });

        $this->assertCount(3, $queries);
        $this->assertIdsMatch($ids, $codes);
    }

    public function test_ids_are_right_when_the_binding_limit_splits_the_rows(): void
    {
        $batch = new SmallBatch($this->app['db']);
        $rows = [];
        $codes = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['code' => "r{$i}", 'name' => 'x'];
            $codes[] = "r{$i}";
        }

        $this->assertIdsMatch($batch->insertGetIds(new User, $rows), $codes);
    }

    public function test_ids_skip_gaps_left_by_deleted_rows(): void
    {
        $this->seedUsers(5);
        DB::table('users')->whereIn('id', [4, 5])->delete();

        $ids = $this->batch()->insertGetIds(new User, [['code' => 'a'], ['code' => 'b']]);

        $this->assertIdsMatch($ids, ['a', 'b']);
        $this->assertGreaterThan(3, min($ids));
    }

    public function test_follows_mysql_auto_increment_increment(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('auto_increment_increment is a MySQL setting.');
        }

        DB::statement('SET SESSION auto_increment_increment = 3');

        $ids = $this->batch()->insertGetIds(new User, [['code' => 'a'], ['code' => 'b'], ['code' => 'c']]);

        $this->assertSame(3, $ids[1] - $ids[0]);
        $this->assertIdsMatch($ids, ['a', 'b', 'c']);
    }

    public function test_applies_enums_json_casts_and_db_raw(): void
    {
        $ids = $this->batch()->insertGetIds(new CastingUser, [
            ['code' => 'a', 'name' => Status::Blocked, 'settings' => ['x' => 1], 'balance' => DB::raw('6 * 7')],
        ]);

        $row = $this->row($ids[0]);
        $this->assertSame('blocked', $row->name);
        $this->assertSame(['x' => 1], json_decode($row->settings, true));
        $this->assertSame(42, (int) $row->balance);
    }

    public function test_uses_the_models_connection(): void
    {
        $ids = $this->batch()->insertGetIds(new SecondaryUser, [['code' => 'a'], ['code' => 'b']]);

        $this->assertIdsMatch($ids, ['a', 'b'], 'secondary');
        $this->assertSame(0, $this->countRows());
    }

    public function test_static_trait_method(): void
    {
        $ids = User::batchInsertGetIds([['code' => 'a'], ['code' => 'b']]);

        $this->assertIdsMatch($ids, ['a', 'b']);
    }

    public function test_pretend_records_the_insert_and_returns_no_ids(): void
    {
        $queries = $this->batch()->pretend(function ($batch) use (&$ids) {
            $ids = $batch->insertGetIds(new User, [['code' => 'a'], ['code' => 'b']]);
        });

        $this->assertSame([], $ids);
        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('insert into', strtolower($queries[0]['sql']));
        $this->assertSame(0, $this->countRows());
    }

    public function test_a_row_that_sets_the_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->insertGetIds(new User, [['code' => 'a'], ['id' => 9, 'code' => 'b']]);
    }

    public function test_a_model_without_an_incrementing_key_throws(): void
    {
        $model = new class extends Model {
            protected $table = 'users';
            public $incrementing = false;
        };

        $this->expectException(InvalidArgumentException::class);

        $this->batch()->insertGetIds($model, [['code' => 'a']]);
    }

    public function test_rows_with_different_columns_throw_before_writing(): void
    {
        try {
            $this->batch()->insertGetIds(new User, [['code' => 'a'], ['name' => 'b']]);
            $this->fail('Expected an exception.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(0, $this->countRows());
        }
    }
}
