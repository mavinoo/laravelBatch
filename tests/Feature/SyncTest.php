<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\SoftDeletingUser;
use Mavinoo\Batch\Tests\Fixtures\Status;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;

class SyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedUsers(5);
    }

    /**
     * @return array<string, string|null> code => name
     */
    private function names(): array
    {
        return DB::table('users')->orderBy('code')->pluck('name', 'code')->all();
    }

    public function test_inserts_updates_and_deletes_to_match_the_list(): void
    {
        $result = $this->batch()->sync(User::query(), [
            ['code' => 'c2', 'name' => 'changed'],
            ['code' => 'c3', 'name' => 'name3'],
            ['code' => 'c6', 'name' => 'new'],
        ], ['code']);

        $this->assertSame(['inserted' => 1, 'updated' => 2, 'deleted' => 3], $result);
        $this->assertSame(['c2' => 'changed', 'c3' => 'name3', 'c6' => 'new'], $this->names());
        $this->assertSame(self::OLD, (string) $this->row(3)->created_at); // updated, not replaced
    }

    public function test_only_deletes_rows_in_the_scope(): void
    {
        DB::table('users')->whereIn('id', [4, 5])->update(['org' => 2]);

        $result = $this->batch()->sync(User::where('org', 1), [['code' => 'c1', 'org' => 1]], 'code');

        $this->assertSame(2, $result['deleted']);
        $this->assertSame(['c1', 'c4', 'c5'], array_keys($this->names()));
    }

    public function test_update_columns_and_the_later_duplicate_wins(): void
    {
        DB::table('users')->update(['phone' => 'keep']);

        $result = $this->batch()->sync(User::query(), [
            ['code' => 'c1', 'name' => 'first', 'phone' => 'x'],
            ['code' => 'c1', 'name' => 'second', 'phone' => 'x'],
            ['code' => 'c2', 'name' => 'two', 'phone' => 'x'],
        ], ['code'], ['name']);

        $this->assertSame(['inserted' => 0, 'updated' => 2, 'deleted' => 3], $result);
        $this->assertSame('second', $this->row(1)->name);
        $this->assertSame('keep', $this->row(1)->phone);
    }

    public function test_an_empty_list_needs_allow_empty(): void
    {
        try {
            $this->batch()->sync(User::query(), [], 'code');
            $this->fail('Expected an exception.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(5, $this->countRows());
        }

        $result = $this->batch()->sync(User::where('id', '>', 3), [], 'code', null, false, true);

        $this->assertSame(['inserted' => 0, 'updated' => 0, 'deleted' => 2], $result);
        $this->assertSame(3, $this->countRows());
    }

    public function test_a_row_without_its_key_rolls_everything_back(): void
    {
        try {
            $this->batch()->sync(User::query(), [['code' => 'c9', 'name' => 'new'], ['name' => 'no key']], 'code', null, false, false, 1);
            $this->fail('Expected an exception.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(5, $this->countRows());
            $this->assertNull(DB::table('users')->where('code', 'c9')->first());
        }

        // The temporary table is gone, so a second sync works.
        $this->assertSame(1, $this->batch()->sync(User::query(), [['code' => 'c1'], ['code' => 'c2'], ['code' => 'c3'], ['code' => 'c4']], 'code')['deleted']);
    }

    public function test_soft_deletes_and_restores_rows(): void
    {
        DB::table('users')->where('id', 5)->update(['deleted_at' => self::OLD]);

        $result = $this->batch()->sync(SoftDeletingUser::query(), [
            ['code' => 'c1', 'name' => 'one'],
            ['code' => 'c5', 'name' => 'back'], // soft-deleted: restored
        ], ['code'], ['name']);

        $this->assertSame(['inserted' => 0, 'updated' => 2, 'deleted' => 3], $result);
        $this->assertSame(5, $this->countRows());
        $this->assertNull($this->row(5)->deleted_at);
        $this->assertSame('back', $this->row(5)->name);
        $this->assertSame(self::NOW, (string) $this->row(2)->deleted_at);

        $forced = $this->batch()->sync(SoftDeletingUser::query(), [['code' => 'c1']], 'code', null, true);

        $this->assertSame(1, $forced['deleted']);
        $this->assertSame(4, $this->countRows()); // c2 - c4 were already soft deleted, so out of the scope
    }

    public function test_composite_keys(): void
    {
        Schema::dropIfExists('scores');
        Schema::create('scores', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('org_id');
            $table->integer('year');
            $table->integer('points')->default(0);
            $table->unique(['org_id', 'year'], 'testing_scores_unique');
        });
        DB::table('scores')->insert([
            ['org_id' => 1, 'year' => 2025, 'points' => 1],
            ['org_id' => 1, 'year' => 2026, 'points' => 2],
            ['org_id' => 2, 'year' => 2025, 'points' => 3],
        ]);
        $score = new class extends Model {
            protected $table = 'scores';
            public $timestamps = false;
        };

        $result = $this->batch()->sync($score->newQuery(), [
            ['org_id' => 1, 'year' => 2026, 'points' => 20],
            ['org_id' => 2, 'year' => 2026, 'points' => 30],
        ], ['org_id', 'year']);

        $this->assertSame(['inserted' => 1, 'updated' => 1, 'deleted' => 2], $result);
        $this->assertSame([20, 30], DB::table('scores')->orderBy('org_id')->pluck('points')->map(fn ($p) => (int) $p)->all());
    }

    public function test_the_database_decides_which_keys_are_equal(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Case-insensitive keys are a MySQL / MariaDB collation feature.');
        }

        DB::table('users')->where('id', 1)->update(['code' => 'ABC']);

        $result = $this->batch()->sync(User::where('id', '<=', 2), [
            ['code' => 'abc', 'name' => 'same row'],
            ['code' => 'c2', 'name' => 'two'],
        ], 'code');

        // A PHP comparison would have deleted the "ABC" row the upsert just updated.
        $this->assertSame(0, $result['deleted']);
        $this->assertSame('same row', $this->row(1)->name);
    }

    public function test_large_lists_are_written_a_chunk_at_a_time(): void
    {
        $rows = function () {
            yield ['code' => 'c1', 'name' => 'kept'];
            for ($i = 100; $i < 2600; $i++) {
                yield ['code' => "c{$i}", 'name' => Status::Active];
            }
        };

        $result = $this->batch()->sync(User::query(), $rows(), 'code', null, false, false, 1000);

        $this->assertSame(['inserted' => 2500, 'updated' => 1, 'deleted' => 4], $result);
        $this->assertSame(2501, $this->countRows());
        $this->assertSame('active', DB::table('users')->where('code', 'c2599')->value('name'));
    }

    public function test_builder_macro_and_pretend(): void
    {
        $this->assertSame(4, User::where('id', '<=', 5)->syncRows([['code' => 'c1']], 'code')['deleted']);

        $queries = $this->batch()->pretend(function ($batch) use (&$result) {
            $result = $batch->sync(User::query(), [['code' => 'zz']], 'code');
        });

        $this->assertSame(['inserted' => 1, 'updated' => 0, 'deleted' => 0], $result);
        $this->assertSame(['c1'], array_keys($this->names()));
        $sql = strtolower(implode("\n", array_column($queries, 'sql')));
        $this->assertStringContainsString('create temporary table', $sql);
        $this->assertStringContainsString('delete from', $sql);
        $this->assertStringContainsString('drop', $sql);
    }
}
