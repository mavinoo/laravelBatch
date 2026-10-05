<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\SmallBatch;
use Mavinoo\Batch\Tests\Fixtures\Status;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;

class ReturningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedUsers(3);
    }

    // upsertReturning

    public function test_returns_inserted_and_updated_rows_in_input_order(): void
    {
        $rows = $this->batch()->upsertReturning(new User, [
            ['code' => 'c9', 'name' => 'new'],
            ['code' => 'c2', 'name' => 'updated'],
        ], ['code'], ['name'], ['id', 'code', 'name']);

        $this->assertCount(2, $rows);
        $this->assertSame(['c9', 'new'], [$rows[0]['code'], $rows[0]['name']]);
        $this->assertSame(4, (int) $rows[0]['id']);
        $this->assertSame(['id', 'code', 'name'], array_keys($rows[0]));
        $this->assertSame([2, 'c2', 'updated'], [(int) $rows[1]['id'], $rows[1]['code'], $rows[1]['name']]);
    }

    public function test_returns_every_column_by_default_and_rows_left_alone_by_only_if(): void
    {
        $rows = $this->batch()->upsertReturning(new User, [
            ['code' => 'c1', 'name' => 'stale', 'updated_at' => '2019-01-01 00:00:00'],
        ], ['code'], null, ['*'], ['onlyIf' => ['updated_at' => '>']]);

        $this->assertSame('name1', $rows[0]['name']);
        $this->assertArrayHasKey('balance', $rows[0]);
    }

    public function test_reads_back_large_batches_in_several_queries(): void
    {
        $batch = new SmallBatch($this->app['db']);
        $input = [];
        for ($i = 1; $i <= 60; $i++) {
            $input[] = ['code' => "c{$i}", 'name' => "v{$i}"];
        }

        $rows = $batch->upsertReturning(new User, $input, 'code', null, ['code', 'name']);

        $this->assertCount(60, $rows);
        $this->assertSame(['code' => 'c60', 'name' => 'v60'], $rows[59]);
    }

    public function test_pretend_returns_no_rows(): void
    {
        $queries = $this->batch()->pretend(function ($batch) use (&$rows) {
            $rows = $batch->upsertReturning(new User, [['code' => 'c9']], 'code');
        });

        $this->assertSame([], $rows);
        $this->assertCount(1, $queries);
        $this->assertSame(3, $this->countRows());
    }

    // insertOrIgnoreRows

    public function test_reports_the_rows_that_were_skipped(): void
    {
        $result = $this->batch()->insertOrIgnoreRows(new User, [
            ['code' => 'c1', 'name' => 'dup'],
            ['code' => 'n1', 'name' => 'new'],
            ['code' => 'c3', 'name' => 'dup'],
            ['code' => 'n2', 'name' => 'new'],
            ['code' => 'n1', 'name' => 'dup in the batch'],
        ], ['code']);

        $this->assertSame(2, $result['inserted']);
        $this->assertSame([
            ['code' => 'c1', 'name' => 'dup'],
            ['code' => 'c3', 'name' => 'dup'],
            ['code' => 'n1', 'name' => 'dup in the batch'],
        ], $result['skipped']);
        $this->assertSame('new', DB::table('users')->where('code', 'n1')->value('name'));
        $this->assertSame(5, $this->countRows());
    }

    public function test_none_and_all_skipped(): void
    {
        $none = $this->batch()->insertOrIgnoreRows(new User, [['code' => 'a'], ['code' => 'b']], 'code');
        $all = $this->batch()->insertOrIgnoreRows(new User, [['code' => 'a'], ['code' => 'c1']], 'code');

        $this->assertSame(['inserted' => 2, 'skipped' => []], $none);
        $this->assertSame(['inserted' => 0, 'skipped' => [['code' => 'a'], ['code' => 'c1']]], $all);
    }

    public function test_reports_across_chunks_with_enums_and_timestamps(): void
    {
        $batch = new SmallBatch($this->app['db']);
        $rows = [];
        for ($i = 1; $i <= 40; $i++) {
            $rows[] = ['code' => $i % 4 === 0 ? 'c1' : "x{$i}", 'name' => Status::Active];
        }

        $result = $batch->insertOrIgnoreRows(new User, $rows, 'code');

        $this->assertSame(30, $result['inserted']);
        $this->assertCount(10, $result['skipped']);
        $this->assertSame(Status::Active, $result['skipped'][0]['name']); // as given
        $this->assertSame(self::NOW, (string) DB::table('users')->where('code', 'x1')->value('created_at'));
    }

    public function test_invalid_input_throws(): void
    {
        $cases = [
            'key column missing' => fn () => $this->batch()->insertOrIgnoreRows(new User, [['name' => 'x']], 'code'),
            'no key' => fn () => $this->batch()->insertOrIgnoreRows(new User, [['code' => 'x']], []),
        ];

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $cases['rows set the id'] = fn () => $this->batch()->insertOrIgnoreRows(new User, [['id' => 50, 'code' => 'x']], 'code');
            $cases['no incrementing key'] = fn () => $this->batch()->insertOrIgnoreRows(new class extends Model {
                protected $table = 'users';
                public $incrementing = false;
            }, [['code' => 'x']], 'code');
        }

        foreach ($cases as $name => $case) {
            try {
                $case();
                $this->fail("{$name}: expected an exception.");
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(3, $this->countRows());
    }

    public function test_rows_may_set_the_id_on_postgresql_and_sqlite(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL needs the auto-increment ids to find the inserted rows.');
        }

        $result = $this->batch()->insertOrIgnoreRows(new User, [['id' => 1, 'code' => 'x'], ['id' => 50, 'code' => 'y']], 'id');

        $this->assertSame(['inserted' => 1, 'skipped' => [['id' => 1, 'code' => 'x']]], $result);
    }
}
