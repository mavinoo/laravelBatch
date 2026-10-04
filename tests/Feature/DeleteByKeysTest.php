<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mavinoo\Batch\Batch;
use Mavinoo\Batch\Tests\Fixtures\SecondaryUser;
use Mavinoo\Batch\Tests\Fixtures\SmallBatch;
use Mavinoo\Batch\Tests\Fixtures\SoftDeletingUser;
use Mavinoo\Batch\Tests\Fixtures\Status;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;

class DeleteByKeysTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedUsers(5);
        DB::table('users')->where('id', 2)->update(['org' => 2]);
    }

    private function ids(string $connection = 'testing'): array
    {
        return DB::connection($connection)->table('users')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_deletes_rows_on_one_key(): void
    {
        $deleted = $this->batch()->deleteByKeys(new User, [['id' => 1], ['id' => 3, 'name' => 'ignored'], ['id' => 99]], ['id']);

        $this->assertSame(2, $deleted);
        $this->assertSame([2, 4, 5], $this->ids());
    }

    public function test_rows_must_match_every_key_column(): void
    {
        $deleted = $this->batch()->deleteByKeys(new User, [
            ['id' => 1, 'org' => 1],
            ['id' => 2, 'org' => 1], // org is 2: no match
            ['id' => 4, 'org' => 1],
        ], ['id', 'org']);

        $this->assertSame(2, $deleted);
        $this->assertSame([2, 3, 5], $this->ids());
    }

    public function test_null_enum_string_and_db_raw_key_values(): void
    {
        DB::table('users')->where('id', 1)->update(['phone' => null, 'name' => 'active']);
        DB::table('users')->where('id', 2)->update(['phone' => '1']);

        $this->batch()->deleteByKeys(new User, [['phone' => null, 'name' => Status::Active]], ['phone', 'name']);
        $this->batch()->deleteByKeys(new User, [['code' => "c3' OR '1'='1"], ['code' => 'c4']], ['code']);
        $this->batch()->deleteByKeys(new User, [['id' => DB::raw('2 + 3')]], ['id']);

        $this->assertSame([2, 3], $this->ids());
    }

    public function test_one_key_with_null_values(): void
    {
        DB::table('users')->whereIn('id', [1, 2])->update(['phone' => '1']);

        $deleted = $this->batch()->deleteByKeys(new User, [['phone' => null], ['phone' => '1']], ['phone']);

        $this->assertSame(5, $deleted);
        $this->assertSame([], $this->ids());
    }

    public function test_soft_deleting_models_are_soft_deleted(): void
    {
        DB::table('users')->where('id', 2)->update(['deleted_at' => self::OLD]);

        $deleted = $this->batch()->deleteByKeys(new SoftDeletingUser, [['id' => 1], ['id' => 2]], ['id']);

        $this->assertSame(1, $deleted); // row 2 was already soft deleted
        $this->assertSame([1, 2, 3, 4, 5], $this->ids());
        $this->assertSame(self::NOW, (string) $this->row(1)->deleted_at);
        $this->assertSame(self::NOW, (string) $this->row(1)->updated_at);
        $this->assertSame(self::OLD, (string) $this->row(2)->deleted_at);
        $this->assertNull($this->row(3)->deleted_at);
    }

    public function test_force_deletes_soft_deleting_models_for_real(): void
    {
        $this->batch()->deleteByKeys(new SoftDeletingUser, [['id' => 1]], ['id'], true);

        $this->assertSame([2, 3, 4, 5], $this->ids());
    }

    public function test_large_batches_are_split_into_several_queries(): void
    {
        DB::table('users')->delete();
        $this->seedUsers(60);

        $rows = [];
        for ($i = 1; $i <= 50; $i++) {
            $rows[] = ['id' => $i, 'code' => "c{$i}"];
        }

        $small = new SmallBatch($this->app['db']);
        $queries = $small->pretend(fn (Batch $batch) => $batch->deleteByKeys(new User, $rows, ['id', 'code']));
        $deleted = $small->deleteByKeys(new User, $rows, ['id', 'code']);

        $this->assertGreaterThan(1, count($queries));
        $this->assertSame(50, $deleted);
        $this->assertSame(range(51, 60), $this->ids());
    }

    public function test_models_on_another_connection(): void
    {
        $this->seedUsers(3, 'secondary');

        $this->batch()->deleteByKeys(new SecondaryUser, [['id' => 1], ['id' => 2]], ['id']);

        $this->assertSame([3], $this->ids('secondary'));
        $this->assertSame([1, 2, 3, 4, 5], $this->ids());
    }

    public function test_pretend_records_without_deleting(): void
    {
        $queries = $this->batch()->pretend(function (Batch $batch) {
            $batch->deleteByKeys(new User, [['id' => 1], ['id' => 2]], ['id']);
            $batch->deleteByKeys(new SoftDeletingUser, [['id' => 3]], ['id']);
        });

        $this->assertCount(2, $queries);
        $this->assertStringStartsWith('DELETE FROM', $queries[0]['sql']);
        $this->assertStringStartsWith('UPDATE', $queries[1]['sql']);
        $this->assertSame([1, 2, 3, 4, 5], $this->ids());
    }

    public function test_static_trait_method(): void
    {
        $this->assertSame(1, User::batchDeleteByKeys([['code' => 'c5']], ['code']));
        $this->assertSame([1, 2, 3, 4], $this->ids());
    }

    public function test_keys_are_required(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->deleteByKeys(new User, [['id' => 1]], []);
    }

    public function test_row_without_a_key_value_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->deleteByKeys(new User, [['id' => 1, 'org' => 1], ['id' => 2]], ['id', 'org']);
    }

    public function test_array_key_value_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->deleteByKeys(new User, [['id' => [1, 2]]], ['id']);
    }

    public function test_returns_zero_without_values(): void
    {
        $queries = $this->writeQueries(function () {
            $this->assertSame(0, $this->batch()->deleteByKeys(new User, [], ['id']));
        });

        $this->assertCount(0, $queries);
    }
}
