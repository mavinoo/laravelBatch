<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;

class SplitFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/batch-split-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->dir . '/*/*') ?: [], glob($this->dir . '/*') ?: []) as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->dir);

        parent::tearDown();
    }

    private function file(string $name, string $contents): string
    {
        file_put_contents($path = $this->dir . '/' . $name, $contents);

        return $path;
    }

    /**
     * @param list<string> $parts
     * @return list<string>
     */
    private function contents(array $parts): array
    {
        return array_map('file_get_contents', $parts);
    }

    public function test_splits_by_number_of_records_and_repeats_the_header(): void
    {
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = "c{$i},name{$i}\n";
        }
        $path = $this->file('users.csv', "code,name\n" . implode('', $lines));

        $parts = $this->batch()->splitFile($path, 3);

        $this->assertSame([
            $this->dir . '/users-001.csv',
            $this->dir . '/users-002.csv',
            $this->dir . '/users-003.csv',
            $this->dir . '/users-004.csv',
        ], $parts);
        $this->assertSame("code,name\n" . implode('', array_slice($lines, 0, 3)), file_get_contents($parts[0]));
        $this->assertSame("code,name\n" . $lines[9], file_get_contents($parts[3]));
        $this->assertSame(file_get_contents($path), "code,name\n" . implode('', array_map(
            fn ($part) => substr($part, strlen("code,name\n")),
            $this->contents($parts)
        )));
    }

    public function test_never_splits_a_quoted_field_that_spans_lines(): void
    {
        $path = $this->file('notes.csv', "id,note\n1,\"first\nsecond\nthird\"\n2,\"say \"\"hi\"\"\"\n3,plain\n");

        $parts = $this->batch()->splitFile($path, 1);

        $this->assertSame([
            "id,note\n1,\"first\nsecond\nthird\"\n",
            "id,note\n2,\"say \"\"hi\"\"\"\n",
            "id,note\n3,plain\n",
        ], $this->contents($parts));
    }

    public function test_splits_by_size_without_cutting_records(): void
    {
        $path = $this->file('data.csv', "h\n" . "aaaa\n" . "bbbb\n" . str_repeat('x', 30) . "\n" . "cccc\n");

        $parts = $this->batch()->splitFile($path, null, 12);

        // "h\n" (2 bytes) + one 5-byte record fits in 12 bytes, two would be 12 too.
        $this->assertSame(["h\naaaa\nbbbb\n", "h\n" . str_repeat('x', 30) . "\n", "h\ncccc\n"], $this->contents($parts));
    }

    public function test_lines_and_bytes_together(): void
    {
        $path = $this->file('data.csv', "h\n1\n2\n3\n4\n");

        $this->assertCount(4, $this->batch()->splitFile($path, 1, 100));
        $this->assertCount(2, $this->batch()->splitFile($path, 100, 6, ['overwrite' => true]));
    }

    public function test_files_without_a_header_and_json_lines(): void
    {
        $csv = $this->batch()->splitFile($this->file('raw.csv', "a\nb\nc\n"), 2, null, ['header' => false]);
        $jsonl = $this->batch()->splitFile($this->file('rows.jsonl', "{\"a\":1}\n{\"a\":2}\n{\"a\":3}"), 2);
        $text = $this->batch()->splitFile($this->file('log.txt', "x \"quote\nnext\n"), 1, null, ['format' => 'lines']);

        $this->assertSame(["a\nb\n", "c\n"], $this->contents($csv));
        $this->assertSame(["{\"a\":1}\n{\"a\":2}\n", '{"a":3}'], $this->contents($jsonl));
        $this->assertSame(["x \"quote\n", "next\n"], $this->contents($text));
    }

    public function test_reads_gzip_files_and_writes_to_another_directory(): void
    {
        mkdir($this->dir . '/out');
        $path = $this->file('users.csv.gz', gzencode("code\na\nb\n"));

        $parts = $this->batch()->splitFile($path, 1, null, ['directory' => $this->dir . '/out']);

        $this->assertSame([$this->dir . '/out/users-001.csv', $this->dir . '/out/users-002.csv'], $parts);
        $this->assertSame("code\nb\n", file_get_contents($parts[1]));
    }

    public function test_existing_parts_are_not_overwritten_unless_asked(): void
    {
        $path = $this->file('users.csv', "code\na\n");
        $this->file('users-001.csv', 'keep me');

        try {
            $this->batch()->splitFile($path, 1);
            $this->fail('Expected an exception.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('keep me', file_get_contents($this->dir . '/users-001.csv'));
        }

        $this->batch()->splitFile($path, 1, null, ['overwrite' => true]);
        $this->assertSame("code\na\n", file_get_contents($this->dir . '/users-001.csv'));
    }

    public function test_empty_files_give_no_parts(): void
    {
        $this->assertSame([], $this->batch()->splitFile($this->file('empty.csv', ''), 10));
        $this->assertSame([], $this->batch()->splitFile($this->file('header.csv', "code,name\n"), 10));
    }

    public function test_parts_can_be_imported(): void
    {
        $rows = "code,name\n";
        for ($i = 1; $i <= 25; $i++) {
            $rows .= "c{$i},\"name, {$i}\"\n";
        }

        foreach ($this->batch()->splitFile($this->file('users.csv', $rows), 10) as $part) {
            $this->batch()->import(new User, $part);
        }

        $this->assertSame(25, $this->countRows());
        $this->assertSame('name, 25', $this->row(25)->name);
    }

    public function test_invalid_arguments_throw(): void
    {
        $path = $this->file('users.csv', "code\na\n");
        $cases = [
            fn () => $this->batch()->splitFile($path),
            fn () => $this->batch()->splitFile($path, 0),
            fn () => $this->batch()->splitFile($path, null, -1),
            fn () => $this->batch()->splitFile($this->dir . '/missing.csv', 1),
            fn () => $this->batch()->splitFile($path, 1, null, ['directory' => $this->dir . '/nope']),
            fn () => $this->batch()->splitFile($path, 1, null, ['lines' => 5]),
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
