<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\CastingUser;
use Mavinoo\Batch\Tests\Fixtures\SecondaryUser;
use Mavinoo\Batch\Tests\Fixtures\SoftDeletingUser;
use Mavinoo\Batch\Tests\Fixtures\Status;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;

class ChunkedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedUsers(25);
    }

    /**
     * Run the callback and return the statements of the given kind it sent.
     */
    private function statements(string $kind, callable $callback): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries, $kind) {
            if (preg_match('/^\s*' . $kind . '\b/i', $query->sql)) {
                $queries[] = $query->sql;
            }
        });

        $callback();

        return $queries;
    }

    private function createArchiveTable(): void
    {
        Schema::dropIfExists('users_archive');
        Schema::create('users_archive', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->timestamp('archived_at')->nullable();
        });
    }

    // deleteInChunks

    public function test_deletes_matching_rows_a_chunk_at_a_time(): void
    {
        $progress = [];

        $deletes = $this->statements('delete', function () use (&$deleted, &$progress) {
            $deleted = $this->batch()->deleteInChunks(User::where('id', '>', 5)->orderByDesc('name'), 7, 0, false, function (int $done) use (&$progress) {
                $progress[] = $done;
            });
        });

        $this->assertSame(20, $deleted);
        $this->assertSame([7, 14, 20], $progress);
        $this->assertCount(3, $deletes);
        $this->assertSame([1, 2, 3, 4, 5], DB::table('users')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_soft_deletes_unless_forced(): void
    {
        $soft = $this->batch()->deleteInChunks(SoftDeletingUser::where('id', '<=', 10), 4);
        $this->assertSame(10, $soft);
        $this->assertSame(25, $this->countRows());
        $this->assertSame(self::NOW, (string) $this->row(10)->deleted_at);

        $forced = $this->batch()->deleteInChunks(SoftDeletingUser::withTrashed()->where('id', '<=', 12), 4, 0, true);
        $this->assertSame(12, $forced);
        $this->assertSame(13, $this->countRows());
    }

    public function test_works_with_query_builders_and_as_a_macro(): void
    {
        $this->assertSame(5, $this->batch()->deleteInChunks(DB::table('users')->where('id', '>', 20), 2));
        $this->assertSame(5, User::where('id', '>', 15)->deleteInChunks(2));
        $this->assertSame(5, DB::table('users')->where('id', '>', 10)->deleteInChunks(chunk: 3));
        $this->assertSame(10, $this->countRows());
    }

    public function test_pauses_between_chunks(): void
    {
        $start = microtime(true);
        $this->batch()->deleteInChunks(User::query(), 10, 60);

        // 25 rows in chunks of 10: two pauses.
        $this->assertGreaterThanOrEqual(0.11, microtime(true) - $start);
        $this->assertSame(0, $this->countRows());
    }

    public function test_pretend_records_the_first_select_and_deletes_nothing(): void
    {
        $queries = $this->batch()->pretend(function ($batch) {
            $this->assertSame(0, $batch->deleteInChunks(User::where('active', true), 10));
        });

        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('select', strtolower($queries[0]['sql']));
        $this->assertSame(25, $this->countRows());
    }

    public function test_invalid_queries_and_chunk_sizes_throw(): void
    {
        $cases = [
            fn () => $this->batch()->deleteInChunks(User::query()->limit(5)),
            fn () => $this->batch()->deleteInChunks(User::query()->offset(5)),
            fn () => $this->batch()->deleteInChunks(User::query(), 0),
            fn () => $this->batch()->deleteInChunks(User::query(), 10, -1),
            fn () => $this->batch()->deleteInChunks(DB::table('users as u')),
            fn () => $this->batch()->updateInChunks(User::query(), []),
            fn () => $this->batch()->updateInChunks(User::query(), ['name']),
        ];

        foreach ($cases as $i => $case) {
            try {
                $case();
                $this->fail("Case {$i}: expected an exception.");
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(25, $this->countRows());
    }

    // updateInChunks

    public function test_update_that_stops_rows_matching_visits_each_row_once(): void
    {
        DB::table('users')->where('id', '>', 20)->update(['active' => false]);

        $updates = $this->statements('update', function () use (&$updated) {
            $updated = $this->batch()->updateInChunks(User::where('active', true), ['active' => false], 6);
        });

        $this->assertSame(20, $updated);
        $this->assertCount(4, $updates);
        $this->assertSame(0, DB::table('users')->where('active', true)->count());
    }

    public function test_update_that_keeps_rows_matching_still_ends(): void
    {
        $updated = $this->batch()->updateInChunks(User::where('org', 1), ['balance' => DB::raw('balance + 1')], 10);

        $this->assertSame(25, $updated);
        $this->assertSame(25 * 101, (int) DB::table('users')->sum('balance'));
    }

    public function test_update_sets_updated_at_enums_json_paths_and_casts(): void
    {
        $this->batch()->updateInChunks(CastingUser::where('id', '<=', 3), [
            'name' => Status::Blocked,
            'settings' => ['theme' => 'dark'],
        ], 2);
        User::where('id', 4)->updateInChunks(['phone' => '123']);
        $this->batch()->updateInChunks(DB::table('users')->where('id', 5), ['phone' => '456']);

        $this->assertSame('blocked', $this->row(3)->name);
        $this->assertSame(['theme' => 'dark'], json_decode($this->row(3)->settings, true));
        $this->assertSame(self::NOW, (string) $this->row(3)->updated_at);
        $this->assertSame('123', $this->row(4)->phone);
        $this->assertSame(self::OLD, (string) $this->row(5)->updated_at); // DB::table() doesn't touch timestamps
        $this->assertSame('456', $this->row(5)->phone);
    }

    // archive

    public function test_archives_rows_into_another_table(): void
    {
        $this->createArchiveTable();
        DB::table('users')->where('id', 3)->update(['name' => "it's"]);

        $archived = $this->batch()->archive(User::where('id', '<=', 10), 'users_archive', 3);

        $this->assertSame(10, $archived);
        $this->assertSame(15, $this->countRows());
        $this->assertSame(10, DB::table('users_archive')->count());
        $copy = DB::table('users_archive')->where('id', 3)->first();
        $this->assertSame("it's", $copy->name);
        $this->assertSame('c3', $copy->code);
        $this->assertSame(self::OLD, (string) $copy->created_at);
        $this->assertNull($copy->archived_at);
    }

    public function test_archive_can_copy_only_and_choose_columns(): void
    {
        $this->createArchiveTable();

        $copied = $this->batch()->archive(User::where('id', '<=', 4), 'users_archive', 3, 0, false, ['id', 'code']);

        $this->assertSame(4, $copied);
        $this->assertSame(25, $this->countRows());
        $this->assertNull(DB::table('users_archive')->where('id', 1)->value('name'));
        $this->assertSame('c4', DB::table('users_archive')->where('id', 4)->value('code'));
    }

    public function test_archive_removes_soft_deleting_rows_for_real(): void
    {
        $this->createArchiveTable();

        SoftDeletingUser::where('id', '<=', 5)->archiveTo('users_archive', 2);

        $this->assertSame(20, $this->countRows());
        $this->assertSame(5, DB::table('users_archive')->count());
    }

    public function test_archive_to_a_model_on_another_connection_can_be_run_again(): void
    {
        // A row copied by an archive that failed before deleting it from the source.
        DB::connection('secondary')->table('users')->insert(['id' => 2, 'code' => 'c2', 'name' => 'name2']);

        $archived = $this->batch()->archive(User::where('id', '<=', 6), new SecondaryUser, 4);

        $this->assertSame(6, $archived);
        $this->assertSame(19, $this->countRows());
        $this->assertSame(6, $this->countRows('secondary'));
        $this->assertSame('name6', $this->row(6, 'secondary')->name);
    }

    public function test_archive_with_no_shared_columns_throws(): void
    {
        Schema::dropIfExists('unrelated');
        Schema::create('unrelated', fn (Blueprint $table) => $table->string('other'));

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->batch()->archive(User::query(), 'unrelated');
        } finally {
            $this->assertSame(25, $this->countRows());
        }
    }

    public function test_pretend_records_the_first_archive_select(): void
    {
        $queries = $this->batch()->pretend(function ($batch) {
            $batch->archive(User::query(), 'users_archive');
        });

        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('select', strtolower($queries[0]['sql']));
    }
}
