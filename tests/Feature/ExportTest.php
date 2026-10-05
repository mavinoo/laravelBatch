<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\SoftDeletingUser;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;

class ExportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedUsers(25);
        $this->dir = sys_get_temp_dir() . '/batch-export-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);

        parent::tearDown();
    }

    public function test_exports_a_query_to_csv_in_key_order(): void
    {
        DB::table('users')->where('id', 2)->update(['name' => "Smith, \"Al\"\nJr", 'phone' => null]);

        $result = $this->batch()->export(User::where('id', '<=', 3)->orderByDesc('name'), $this->dir . '/users.csv', [
            'columns' => ['code', 'name', 'phone'],
            'chunk' => 2,
        ]);

        $this->assertSame(['totalRows' => 3, 'files' => [$this->dir . '/users.csv']], $result);
        $this->assertSame(
            "code,name,phone\nc1,name1,\nc2,\"Smith, \"\"Al\"\"\nJr\",\nc3,name3,\n",
            file_get_contents($this->dir . '/users.csv')
        );
    }

    public function test_every_column_by_default_and_jsonl_tsv_and_gzip(): void
    {
        $this->batch()->export(User::where('id', 1), $this->dir . '/one.jsonl');
        $this->batch()->export(DB::table('users')->select('id', 'code')->where('id', '<=', 2), $this->dir . '/two.tsv');
        $this->batch()->export(User::where('id', 1), $this->dir . '/one.csv.gz', ['columns' => ['code']]);

        $json = json_decode(trim(file_get_contents($this->dir . '/one.jsonl')), true);
        $this->assertSame('c1', $json['code']);
        $this->assertArrayHasKey('balance', $json);
        $this->assertSame("id\tcode\n1\tc1\n2\tc2\n", file_get_contents($this->dir . '/two.tsv'));
        $this->assertSame("code\nc1\n", gzdecode(file_get_contents($this->dir . '/one.csv.gz')));
    }

    public function test_splits_into_parts_and_reports_progress(): void
    {
        $progress = [];

        $result = $this->batch()->export(User::query(), $this->dir . '/users.csv', [
            'columns' => ['id'],
            'maxRows' => 10,
            'chunk' => 7,
            'onChunk' => function (int $written) use (&$progress) {
                $progress[] = $written;
            },
        ]);

        $this->assertSame(25, $result['totalRows']);
        $this->assertSame([$this->dir . '/users-001.csv', $this->dir . '/users-002.csv', $this->dir . '/users-003.csv'], $result['files']);
        $this->assertSame([7, 14, 21, 25], $progress);
        $this->assertSame("id\n21\n22\n23\n24\n25\n", file_get_contents($result['files'][2]));
    }

    public function test_transform_scopes_and_round_trip_through_import(): void
    {
        DB::table('users')->where('id', 3)->update(['deleted_at' => self::OLD]);

        $result = $this->batch()->export(SoftDeletingUser::query(), $this->dir . '/users.csv', [
            'columns' => ['code', 'name'],
            'transform' => fn (array $row) => $row['code'] === 'c4' ? null : ['code' => 'x' . $row['code'], 'name' => strtoupper($row['name'])],
        ]);

        $this->assertSame(23, $result['totalRows']); // c3 is soft deleted, c4 left out
        $this->batch()->import(new User, $this->dir . '/users.csv');
        $this->assertSame('NAME25', DB::table('users')->where('code', 'xc25')->value('name'));
    }

    public function test_existing_files_need_overwrite(): void
    {
        file_put_contents($this->dir . '/users.csv', 'keep');

        try {
            $this->batch()->export(User::query(), $this->dir . '/users.csv');
            $this->fail('Expected an exception.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('keep', file_get_contents($this->dir . '/users.csv'));
        }

        $this->batch()->export(User::where('id', 1), $this->dir . '/users.csv', ['columns' => ['code'], 'overwrite' => true]);
        $this->assertSame("code\nc1\n", file_get_contents($this->dir . '/users.csv'));
    }

    public function test_macro_and_pretend(): void
    {
        User::where('id', '<=', 2)->exportTo($this->dir . '/macro.csv', ['columns' => ['code']]);
        $this->assertSame("code\nc1\nc2\n", file_get_contents($this->dir . '/macro.csv'));

        $queries = $this->batch()->pretend(function ($batch) use (&$result) {
            $result = $batch->export(User::query(), $this->dir . '/pretend.csv');
        });

        $this->assertSame(['totalRows' => 0, 'files' => []], $result);
        $this->assertFileDoesNotExist($this->dir . '/pretend.csv');
        $this->assertStringStartsWith('select', strtolower($queries[0]['sql']));
    }

    public function test_invalid_options_throw(): void
    {
        $cases = [
            fn () => $this->batch()->export(User::query(), $this->dir . '/a.csv', ['rows' => 5]),
            fn () => $this->batch()->export(User::query()->limit(5), $this->dir . '/a.csv'),
            fn () => $this->batch()->export(User::query(), $this->dir . '/a.xlsx'),
            fn () => $this->batch()->export(User::query(), $this->dir . '/a.csv', ['maxRows' => 0]),
            fn () => $this->batch()->export(User::query(), $this->dir . '/missing/a.csv'),
        ];

        foreach ($cases as $i => $case) {
            try {
                $case();
                $this->fail("Case {$i}: expected an exception.");
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
