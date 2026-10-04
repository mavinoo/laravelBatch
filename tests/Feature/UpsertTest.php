<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\SecondaryUser;
use Mavinoo\Batch\Tests\Fixtures\SmallBatch;
use Mavinoo\Batch\Tests\Fixtures\Status;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\Fixtures\UserWithoutTimestamps;
use Mavinoo\Batch\Tests\TestCase;

class UpsertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedUsers(2);
        DB::table('users')->where('id', 1)->update(['phone' => '0912']);
    }

    public function test_inserts_new_rows_and_updates_existing_ones(): void
    {
        $affected = $this->batch()->upsert(new User, [
            ['code' => 'c1', 'name' => 'updated', 'phone' => 'ignored'],
            ['code' => 'c9', 'name' => 'new', 'phone' => '1'],
        ], ['code'], ['name']);

        $this->assertGreaterThan(0, $affected);
        $this->assertSame(3, $this->countRows());

        $existing = DB::table('users')->where('code', 'c1')->first();
        $this->assertSame('updated', $existing->name);
        $this->assertSame('0912', $existing->phone); // not in the update list
        $this->assertSame(self::OLD, (string) $existing->created_at);
        $this->assertSame(self::NOW, (string) $existing->updated_at);

        $new = DB::table('users')->where('code', 'c9')->first();
        $this->assertSame('new', $new->name);
        $this->assertSame('1', $new->phone);
        $this->assertSame(self::NOW, (string) $new->created_at);
        $this->assertSame(self::NOW, (string) $new->updated_at);
    }

    public function test_null_update_updates_every_given_column(): void
    {
        $this->batch()->upsert(new User, [['code' => 'c2', 'name' => "o'reilly", 'phone' => '09121234567']], 'code');

        $row = DB::table('users')->where('code', 'c2')->first();
        $this->assertSame("o'reilly", $row->name);
        $this->assertSame('09121234567', $row->phone);
        $this->assertSame(self::OLD, (string) $row->created_at);
        $this->assertSame(2, $this->countRows());
    }

    public function test_enums_and_models_without_timestamps(): void
    {
        $this->batch()->upsert(new UserWithoutTimestamps, [
            ['code' => 'c1', 'name' => Status::Blocked],
            ['code' => 'c3', 'name' => Status::Active],
        ], ['code']);

        $this->assertSame('blocked', DB::table('users')->where('code', 'c1')->value('name'));
        $this->assertSame('active', DB::table('users')->where('code', 'c3')->value('name'));
        $this->assertSame(self::OLD, (string) DB::table('users')->where('code', 'c1')->value('updated_at'));
        $this->assertNull(DB::table('users')->where('code', 'c3')->value('created_at'));
    }

    public function test_large_batches_are_split_into_several_queries(): void
    {
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['code' => "c{$i}", 'name' => "n{$i}"];
        }

        $queries = $this->writeQueries(function () use ($rows) {
            (new SmallBatch($this->app['db']))->upsert(new User, $rows, ['code']);
        });

        $this->assertGreaterThan(1, count($queries));
        $this->assertSame(30, $this->countRows());
        $this->assertSame('n1', DB::table('users')->where('code', 'c1')->value('name'));
        $this->assertSame('n30', DB::table('users')->where('code', 'c30')->value('name'));
    }

    public function test_models_on_another_connection(): void
    {
        $this->seedUsers(1, 'secondary');

        $this->batch()->upsert(new SecondaryUser, [['code' => 'c1', 'name' => 'x'], ['code' => 'c2', 'name' => 'y']], ['code']);

        $this->assertSame(2, $this->countRows('secondary'));
        $this->assertSame('x', $this->row(1, 'secondary')->name);
        $this->assertSame(2, $this->countRows()); // default connection untouched
    }

    public function test_returns_zero_without_values(): void
    {
        $this->assertSame(0, $this->batch()->upsert(new User, [], ['code']));
    }

    public function test_unique_by_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->upsert(new User, [['code' => 'c1', 'name' => 'x']], []);
    }

    public function test_an_empty_update_list_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('insertRows()');

        $this->batch()->upsert(new User, [['code' => 'c1', 'name' => 'x']], ['code'], []);
    }

    public function test_rows_must_contain_the_unique_by_columns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->upsert(new User, [['name' => 'x']], ['code']);
    }

    public function test_rows_with_different_columns_throw(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->upsert(new User, [['code' => 'c1', 'name' => 'x'], ['code' => 'c2']], ['code']);
    }
}
