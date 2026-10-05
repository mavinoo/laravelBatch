<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\CastingUser;
use Mavinoo\Batch\Tests\Fixtures\SecondaryUser;
use Mavinoo\Batch\Tests\Fixtures\Status;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;

class ImportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/batch-import-' . bin2hex(random_bytes(6));
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

    private function file(string $name, string $contents): string
    {
        file_put_contents($path = $this->dir . '/' . $name, $contents);

        return $path;
    }

    public function test_imports_a_csv_file(): void
    {
        $path = $this->file('users.csv', "\xEF\xBB\xBFcode,name,phone\r\n"
            . "c1,\"Smith, \"\"Al\"\"\",0912\r\n"
            . "c2,\"two\nlines\",\r\n"
            . "\r\n"
            . "c3,فارسی ✓,123\r\n");

        $result = $this->batch()->import(new User, $path);

        $this->assertSame(['totalRows' => 3, 'skipped' => 0, 'errors' => []], $result);
        $this->assertSame('Smith, "Al"', $this->row(1)->name);
        $this->assertSame('0912', $this->row(1)->phone);
        $this->assertSame("two\nlines", $this->row(2)->name);
        $this->assertSame('', $this->row(2)->phone);
        $this->assertSame('فارسی ✓', $this->row(3)->name);
        $this->assertSame(self::NOW, (string) $this->row(3)->created_at);
    }

    public function test_imports_tsv_json_lines_and_gzip_files(): void
    {
        $this->batch()->import(new User, $this->file('a.tsv', "code\tname\nt1\tTab\n"));
        $this->batch()->import(new User, $this->file('b.jsonl', "{\"code\":\"j1\",\"active\":false,\"balance\":7}\n\n{\"code\":\"j2\",\"active\":true,\"balance\":8}"));
        $this->batch()->import(new User, $this->file('c.csv.gz', gzencode("code,name\ng1,Zipped\n")));

        $this->assertSame('Tab', DB::table('users')->where('code', 't1')->value('name'));
        $this->assertFalse((bool) DB::table('users')->where('code', 'j1')->value('active'));
        $this->assertSame(8, (int) DB::table('users')->where('code', 'j2')->value('balance'));
        $this->assertSame('Zipped', DB::table('users')->where('code', 'g1')->value('name'));
    }

    public function test_imports_from_an_open_stream(): void
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, "code;name\ns1;Stream\n");
        rewind($stream);

        $this->batch()->import(new User, $stream, ['delimiter' => ';']);
        fclose($stream);

        $this->assertSame('Stream', DB::table('users')->where('code', 's1')->value('name'));
    }

    public function test_imports_from_iterables(): void
    {
        $generator = (function () {
            yield ['code' => 'a', 'name' => 'Array'];
            yield (object) ['code' => 'b', 'name' => 'Object'];
        })();

        $this->batch()->import(new User, $generator);
        $this->batch()->import(new User, LazyCollection::make(fn () => yield ['code' => 'c', 'name' => 'Lazy']));

        $this->assertSame(['Array', 'Object', 'Lazy'], DB::table('users')->orderBy('id')->pluck('name')->all());
    }

    public function test_copies_models_from_another_connection(): void
    {
        $this->seedUsers(5, 'secondary');

        $result = $this->batch()->import(new User, SecondaryUser::query()->orderBy('id')->cursor(), ['chunk' => 2]);

        $this->assertSame(5, $result['totalRows']);
        $this->assertSame('name5', $this->row(5)->name);
        $this->assertSame(self::OLD, (string) $this->row(5)->created_at);
    }

    public function test_writes_a_chunk_at_a_time(): void
    {
        $rows = function () {
            for ($i = 1; $i <= 2500; $i++) {
                yield ['code' => "c{$i}"];
            }
        };

        $progress = [];
        $this->batch()->import(new User, $rows(), [
            'chunk' => 1000,
            'onChunk' => function (int $written) use (&$progress) {
                // Each chunk is written before the next one is read.
                $progress[] = [$written, $this->countRows()];
            },
        ]);

        $this->assertSame([[1000, 1000], [2000, 2000], [2500, 2500]], $progress);
    }

    public function test_insert_ignore_and_upsert_modes(): void
    {
        $this->seedUsers(2);

        $ignored = $this->batch()->import(new User, [
            ['code' => 'c1', 'name' => 'dup'],
            ['code' => 'c9', 'name' => 'new'],
        ], ['mode' => 'insertIgnore']);

        $this->batch()->import(new User, [
            ['code' => 'c2', 'name' => 'updated', 'phone' => '1'],
            ['code' => 'c10', 'name' => 'inserted', 'phone' => '2'],
        ], ['mode' => 'upsert', 'uniqueBy' => 'code', 'update' => ['name']]);

        $this->assertSame(2, $ignored['totalRows']);
        $this->assertSame('name1', $this->row(1)->name);
        $this->assertSame('updated', $this->row(2)->name);
        $this->assertNull($this->row(2)->phone);
        $this->assertSame('inserted', DB::table('users')->where('code', 'c10')->value('name'));
        $this->assertSame(4, $this->countRows());
    }

    public function test_map_null_values_and_transform(): void
    {
        $path = $this->file('people.csv', "E-mail,Full name,Notes\nc1,Ali,x\nc2,,y\nskip,Bad,z\n");
        $numbers = [];

        $result = $this->batch()->import(new User, $path, [
            'map' => ['E-mail' => 'code', 'Full name' => 'name'],
            'nullValues' => [''],
            'transform' => function (array $row, int $number) use (&$numbers) {
                $numbers[] = $number;

                return $row['code'] === 'skip' ? null : $row + ['phone' => "line {$number}"];
            },
        ]);

        $this->assertSame(['totalRows' => 2, 'skipped' => 1, 'errors' => []], $result);
        $this->assertSame([2, 3, 4], $numbers);
        $this->assertSame('Ali', $this->row(1)->name);
        $this->assertNull($this->row(2)->name);
        $this->assertSame('line 3', $this->row(2)->phone);
    }

    public function test_files_without_a_header(): void
    {
        $this->batch()->import(new User, $this->file('raw.csv', "c1,Ali\nc2,Sara\n"), ['columns' => ['code', 'name']]);
        $this->batch()->import(new User, $this->file('skip.csv', "ignored,header\nc3,Reza\n"), ['columns' => ['code', 'name'], 'header' => true]);

        $this->assertSame(['Ali', 'Sara', 'Reza'], DB::table('users')->orderBy('id')->pluck('name')->all());
    }

    public function test_enums_and_json_casts_are_applied(): void
    {
        $this->batch()->import(new CastingUser, [['code' => 'a', 'name' => Status::Active, 'settings' => ['x' => 1]]]);

        $this->assertSame('active', $this->row(1)->name);
        $this->assertSame(['x' => 1], json_decode($this->row(1)->settings, true));
    }

    public function test_a_malformed_row_stops_the_import_and_writes_nothing(): void
    {
        $path = $this->file('bad.csv', "code,name\nc1,Ali\nc2,Sara,extra\n");

        try {
            $this->batch()->import(new User, $path, ['chunk' => 1]);
            $this->fail('Expected an exception.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Row 3', $e->getMessage());
            $this->assertStringContainsString('3 fields, but there are 2 columns', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows());
    }

    public function test_on_error_skips_malformed_rows(): void
    {
        $path = $this->file('mixed.jsonl', "{\"code\":\"a\"}\nnot json\n[1,2]\n{\"code\":\"b\",\"name\":\"other columns\"}\n{\"code\":\"c\"}\n");
        $errors = [];

        $result = $this->batch()->import(new User, $path, [
            'onError' => function (InvalidArgumentException $e, int $number) use (&$errors) {
                $errors[$number] = $e->getMessage();
            },
        ]);

        $this->assertSame(['totalRows' => 2, 'skipped' => 3, 'errors' => []], $result);
        $this->assertSame([2, 3, 4], array_keys($errors));
        $this->assertStringContainsString('not valid JSON', $errors[2]);
        $this->assertStringContainsString('JSON object', $errors[3]);
        $this->assertStringContainsString('the first row has [code]', $errors[4]);
        $this->assertSame(['a', 'c'], DB::table('users')->orderBy('id')->pluck('code')->all());
    }

    public function test_atomic_import_rolls_back_and_non_atomic_keeps_finished_chunks(): void
    {
        $rows = [['code' => 'a'], ['code' => 'b'], ['code' => 'a']]; // the last one breaks the unique index

        foreach ([true, false] as $atomic) {
            try {
                $this->batch()->import(new User, $rows, ['chunk' => 2, 'atomic' => $atomic]);
                $this->fail('Expected a database error.');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertSame($atomic ? 0 : 2, $this->countRows());
            }
        }
    }

    public function test_static_trait_method_and_pretend(): void
    {
        User::batchImport([['code' => 'a']]);

        $queries = $this->batch()->pretend(function ($batch) {
            $batch->import(new User, [['code' => 'b'], ['code' => 'c']]);
        });

        $this->assertSame(1, $this->countRows());
        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('insert into', strtolower($queries[0]['sql']));
    }

    public function test_invalid_input_throws(): void
    {
        $cases = [
            'unknown option' => fn () => $this->batch()->import(new User, [], ['chunks' => 5]),
            'bad mode' => fn () => $this->batch()->import(new User, [], ['mode' => 'replace']),
            'upsert without uniqueBy' => fn () => $this->batch()->import(new User, [], ['mode' => 'upsert']),
            'bad chunk' => fn () => $this->batch()->import(new User, [], ['chunk' => 0]),
            'missing file' => fn () => $this->batch()->import(new User, $this->dir . '/missing.csv'),
            'unknown extension' => fn () => $this->batch()->import(new User, $this->file('data.xlsx', 'x')),
            'duplicate header' => fn () => $this->batch()->import(new User, $this->file('dup.csv', "code,code\na,b\n")),
            'mapped column missing' => fn () => $this->batch()->import(new User, [['code' => 'a']], ['map' => ['email' => 'code']]),
            'not a source' => fn () => $this->batch()->import(new User, 42),
            'row is not an array' => fn () => $this->batch()->import(new User, ['oops']),
        ];

        foreach ($cases as $name => $case) {
            try {
                $case();
                $this->fail("{$name}: expected an exception.");
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $this->countRows());
    }
}
