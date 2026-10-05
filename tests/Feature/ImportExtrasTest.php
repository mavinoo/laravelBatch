<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\CastingUser;
use Mavinoo\Batch\Tests\Fixtures\Status;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;

/**
 * import() validation rules and "fast" bulk loading.
 */
class ImportExtrasTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/batch-import-extras-' . bin2hex(random_bytes(6));
        mkdir($this->dir);

        if (DB::getDriverName() === 'mysql' && !str_contains(strtolower((string) DB::selectOne('select version() as v')->v), 'mariadb')) {
            // MySQL 8 ships with local_infile off.
            DB::statement('SET GLOBAL local_infile = 1');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);

        parent::tearDown();
    }

    // rules

    public function test_rows_failing_the_rules_are_skipped_and_reported(): void
    {
        $path = $this->dir . '/users.csv';
        file_put_contents($path, "code,phone,balance\nc1,0912,10\n,0913,20\nc3,,abc\nc4,0914,40\n");

        $result = $this->batch()->import(new User, $path, [
            'rules' => ['code' => 'required', 'balance' => 'integer|min:0'],
            'nullValues' => [''],
        ]);

        $this->assertSame(2, $result['totalRows']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame([3, 4], array_keys($result['errors']));
        $this->assertArrayHasKey('code', $result['errors'][3]);
        $this->assertArrayHasKey('balance', $result['errors'][4]);
        $this->assertSame(['c1', 'c4'], DB::table('users')->orderBy('id')->pluck('code')->all());
    }

    public function test_rules_see_mapped_columns_and_run_before_transform(): void
    {
        $seen = [];

        $result = $this->batch()->import(new User, [
            ['Code' => 'a', 'Mail' => 'ali@example.com'],
            ['Code' => 'b', 'Mail' => 'not an email'],
        ], [
            'map' => ['Code' => 'code', 'Mail' => 'name'],
            'rules' => ['name' => 'email'],
            'transform' => function (array $row) use (&$seen) {
                $seen[] = $row['code'];

                return $row;
            },
        ]);

        $this->assertSame(['a'], $seen);
        $this->assertSame(1, $result['totalRows']);
        $this->assertSame([2], array_keys($result['errors']));
    }

    public function test_invalid_rules_option_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->import(new User, [['code' => 'a']], ['rules' => 'required']);
    }

    // fast

    public function test_fast_import_loads_every_kind_of_value(): void
    {
        $path = $this->dir . '/users.jsonl';
        $lines = [
            ['code' => 'a', 'name' => "tab\there", 'phone' => "new\nline", 'active' => false, 'balance' => 5],
            ['code' => 'b', 'name' => 'back\\slash \\N', 'phone' => null, 'active' => true, 'balance' => 0],
            ['code' => 'c', 'name' => 'فارسی ✓ "quoted"', 'phone' => '', 'active' => true, 'balance' => -3],
        ];
        file_put_contents($path, implode("\n", array_map('json_encode', $lines)) . "\n");

        $result = $this->batch()->import(new User, $path, ['fast' => true]);

        $this->assertSame(3, $result['totalRows']);
        $a = DB::table('users')->where('code', 'a')->first();
        $b = DB::table('users')->where('code', 'b')->first();
        $c = DB::table('users')->where('code', 'c')->first();
        $this->assertSame("tab\there", $a->name);
        $this->assertSame("new\nline", $a->phone);
        $this->assertFalse((bool) $a->active);
        $this->assertSame('back\\slash \\N', $b->name);
        $this->assertNull($b->phone);
        $this->assertSame('', $c->phone);
        $this->assertSame('فارسی ✓ "quoted"', $c->name);
        $this->assertSame(-3, (int) $c->balance);
        $this->assertSame(self::NOW, (string) $c->created_at);
    }

    public function test_fast_import_applies_enums_casts_and_chunks(): void
    {
        $rows = function () {
            yield ['code' => 'json', 'name' => Status::Blocked, 'settings' => ['x' => 1]];
            for ($i = 1; $i <= 2500; $i++) {
                yield ['code' => "r{$i}", 'name' => Status::Active, 'settings' => null];
            }
        };

        $progress = [];
        $result = $this->batch()->import(new CastingUser, $rows(), [
            'fast' => true,
            'chunk' => 1000,
            'onChunk' => function (int $written) use (&$progress) {
                $progress[] = $written;
            },
        ]);

        $this->assertSame(2501, $result['totalRows']);
        $this->assertSame([1000, 2000, 2501], $progress);
        $this->assertSame('blocked', DB::table('users')->where('code', 'json')->value('name'));
        $this->assertSame(['x' => 1], json_decode(DB::table('users')->where('code', 'json')->value('settings'), true));
        $this->assertSame(2501, $this->countRows());
    }

    public function test_fast_import_rolls_back_on_a_duplicate_key(): void
    {
        DB::table('users')->insert(['code' => 'dup']);

        try {
            $this->batch()->import(new User, [['code' => 'new1'], ['code' => 'dup'], ['code' => 'new2']], ['fast' => true]);
            $this->fail('Expected an exception.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }

        $this->assertSame(['dup'], DB::table('users')->pluck('code')->all());
    }

    public function test_fast_import_fails_on_bad_data_instead_of_storing_it(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite stores any value in any column.');
        }

        try {
            // MySQL's LOAD DATA LOCAL would store 0 with a warning; that must not pass silently.
            $this->batch()->import(new User, [['code' => 'a', 'balance' => 1], ['code' => 'b', 'balance' => 'abc']], ['fast' => true]);
            $this->fail('Expected an exception.');
        } catch (\RuntimeException|\Illuminate\Database\QueryException|\PDOException $e) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $this->countRows());
    }

    public function test_fast_import_only_inserts_and_rejects_raw_values(): void
    {
        try {
            $this->batch()->import(new User, [['code' => 'a']], ['fast' => true, 'mode' => 'upsert', 'uniqueBy' => 'code']);
            $this->fail('Expected an exception.');
        } catch (InvalidArgumentException $e) {
            $this->addToAssertionCount(1);
        }

        if (DB::getDriverName() === 'sqlite') {
            return; // SQLite imports as usual, where DB::raw() works
        }

        $this->expectException(InvalidArgumentException::class);
        $this->batch()->import(new User, [['code' => 'a', 'balance' => DB::raw('1 + 1')]], ['fast' => true]);
    }

    public function test_pretend_records_the_bulk_load(): void
    {
        $queries = $this->batch()->pretend(function ($batch) {
            $batch->import(new User, [['code' => 'a'], ['code' => 'b']], ['fast' => true]);
        });

        $this->assertCount(1, $queries);
        $expected = ['pgsql' => 'COPY ', 'mysql' => 'LOAD DATA LOCAL INFILE', 'mariadb' => 'LOAD DATA LOCAL INFILE', 'sqlite' => 'insert into'][DB::getDriverName()];
        $this->assertStringStartsWith($expected, $queries[0]['sql']);
        $this->assertSame(0, $this->countRows());
    }
}
