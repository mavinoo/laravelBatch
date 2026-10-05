<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mavinoo\Batch\Tests\Fixtures\CastingUser;
use Mavinoo\Batch\Tests\Fixtures\Status;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class JsonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedUsers();
        // Stored the way MySQL and MariaDB format JSON, so unchanged documents compare equal everywhere.
        DB::table('users')->where('id', 1)->update(['settings' => '{"theme": "light", "lang": "fa"}']);
        DB::table('users')->where('id', 2)->update(['settings' => '{"notify": {"email": true, "push": true}}']);
    }

    private function settings(int $id): mixed
    {
        $json = $this->row($id)->settings;

        return is_null($json) ? null : json_decode($json, true);
    }

    /**
     * Compare a row's settings with $expected, ignoring key order (PostgreSQL's jsonb sorts keys).
     */
    private function assertSettings(?array $expected, int $id): void
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };

        $this->assertSame($sort($expected), $sort($this->settings($id)));
    }

    public function test_sets_a_key_inside_a_json_column(): void
    {
        $affected = $this->batch()->update(new User, [
            ['id' => 1, 'settings->theme' => 'dark'],
            ['id' => 2, 'settings->notify->email' => false],
        ]);

        $this->assertSame(2, $affected);
        $this->assertSettings(['theme' => 'dark', 'lang' => 'fa'], 1);
        $this->assertSettings(['notify' => ['email' => false, 'push' => true]], 2);
        $this->assertSettings(null, 3);
    }

    public function test_sets_several_keys_of_one_column_in_a_row(): void
    {
        $this->batch()->update(new User, [
            ['id' => 1, 'settings->theme' => 'dark', 'settings->lang' => 'en', 'name' => 'ali'],
        ]);

        $this->assertSettings(['theme' => 'dark', 'lang' => 'en'], 1);
        $this->assertSame('ali', $this->row(1)->name);
    }

    public function test_creates_missing_objects_and_starts_null_columns_as_an_object(): void
    {
        $this->batch()->update(new User, [
            ['id' => 1, 'settings->notify->email' => true, 'settings->notify->sms' => false],
            ['id' => 3, 'settings->a->b->c' => 1],
        ]);

        $this->assertSettings(['theme' => 'light', 'lang' => 'fa', 'notify' => ['email' => true, 'sms' => false]], 1);
        $this->assertSettings(['a' => ['b' => ['c' => 1]]], 3);
    }

    public function test_keeps_the_type_of_each_value(): void
    {
        $this->batch()->update(new User, [[
            'id' => 3,
            'settings->int' => 7,
            'settings->float' => 1.5,
            'settings->true' => true,
            'settings->false' => false,
            'settings->null' => null,
            'settings->numeric_string' => '0912',
            'settings->text' => "it's \"quoted\" \\ فارسی",
            'settings->list' => [1, 'two'],
            'settings->object' => ['k' => 'v'],
            'settings->enum' => Status::Blocked,
        ]]);

        $this->assertSettings([
            'int' => 7,
            'float' => 1.5,
            'true' => true,
            'false' => false,
            'null' => null,
            'numeric_string' => '0912',
            'text' => "it's \"quoted\" \\ فارسی",
            'list' => [1, 'two'],
            'object' => ['k' => 'v'],
            'enum' => 'blocked',
        ], 3);
    }

    public function test_touches_updated_at_only_when_the_json_changes(): void
    {
        $this->batch()->update(new User, [
            ['id' => 1, 'settings->theme' => 'light'],          // unchanged
            ['id' => 2, 'settings->notify->email' => false],    // changed
            ['id' => 3, 'settings->theme' => 'dark'],           // was NULL
        ]);

        $this->assertSame(self::OLD, (string) $this->row(1)->updated_at);
        $this->assertSame(self::NOW, (string) $this->row(2)->updated_at);
        $this->assertSame(self::NOW, (string) $this->row(3)->updated_at);
    }

    public function test_works_with_every_update_method(): void
    {
        $this->batch()->updateByKeys(new User, [['code' => 'c1', 'org' => 1, 'settings->theme' => 'blue']], ['code', 'org']);
        $this->batch()->updateMultipleCondition(new User, [
            ['conditions' => ['id' => 2], 'columns' => ['settings->notify->push' => false]],
        ]);

        $this->assertSame('blue', $this->settings(1)['theme']);
        $this->assertFalse($this->settings(2)['notify']['push']);
    }

    public function test_raw_expressions_are_stored_as_json(): void
    {
        $raw = [
            'mysql' => 'JSON_ARRAY(1, 2)',
            'mariadb' => 'JSON_ARRAY(1, 2)',
            'pgsql' => 'jsonb_build_array(1, 2)',
            'sqlite' => 'json_array(1, 2)',
        ][DB::getDriverName()];

        $this->batch()->update(new User, [['id' => 1, 'settings->theme' => DB::raw($raw)]]);

        $this->assertSame([1, 2], $this->settings(1)['theme']);
    }

    public function test_paths_and_values_are_bound(): void
    {
        $value = "x'); DROP TABLE users; --";

        $queries = $this->batch()->pretend(function ($batch) use ($value) {
            $batch->update(new User, [['id' => 1, 'settings->my key' => $value]]);
        });

        $this->assertStringNotContainsString('DROP', $queries[0]['sql']);
        $this->assertStringNotContainsString('my key', $queries[0]['sql']);
        $this->assertContains(json_encode($value), $queries[0]['bindings']);

        $this->batch()->update(new User, [['id' => 1, 'settings->my key' => $value]]);
        $this->assertSame($value, $this->settings(1)['my key']);
    }

    public function test_setting_a_column_and_a_path_inside_it_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->update(new User, [['id' => 1, 'settings' => '{}', 'settings->theme' => 'dark']]);
    }

    #[DataProvider('invalidPaths')]
    public function test_invalid_paths_throw(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batch()->update(new User, [['id' => 1, $path => 'x']]);
    }

    public static function invalidPaths(): array
    {
        return [
            'empty key' => ['settings->'],
            'empty column' => ['->theme'],
            'empty middle key' => ['settings->->theme'],
            'quote' => ['settings->a"b'],
            'backslash' => ['settings->a\\b'],
        ];
    }

    public function test_whole_json_column_can_be_updated_with_a_string(): void
    {
        // PostgreSQL has no "=" operator for json, so this failed there before 3.2.
        $this->batch()->update(new User, [
            ['id' => 1, 'settings' => '{"theme": "dark"}'],
            ['id' => 2, 'settings' => null],
        ]);

        $this->assertSettings(['theme' => 'dark'], 1);
        $this->assertSettings(null, 2);
        $this->assertSame(self::NOW, (string) $this->row(1)->updated_at);
    }

    public function test_an_unchanged_json_column_keeps_its_updated_at(): void
    {
        $this->batch()->update(new User, [['id' => 1, 'settings' => '{"theme": "light", "lang": "fa"}']]);

        $this->assertSame(self::OLD, (string) $this->row(1)->updated_at);
    }

    public function test_arrays_are_encoded_for_json_cast_columns(): void
    {
        $this->batch()->update(new CastingUser, [
            ['id' => 1, 'settings' => ['theme' => 'dark', 'list' => [1, 2]]],
            ['id' => 2, 'meta' => new Collection(['tags' => ['a']])],
            ['id' => 3, 'balance' => ['+', 5]], // not a JSON cast: still arithmetic
        ]);

        $this->assertSettings(['theme' => 'dark', 'list' => [1, 2]], 1);
        $this->assertSame(['tags' => ['a']], json_decode($this->row(2)->meta, true));
        $this->assertSame(105, (int) $this->row(3)->balance);
    }

    public function test_arrays_are_encoded_for_json_cast_columns_on_insert_and_upsert(): void
    {
        $this->batch()->insertRows(new CastingUser, [
            ['id' => 10, 'code' => 'c10', 'settings' => ['a' => 1]],
            ['id' => 11, 'code' => 'c11', 'settings' => null],
        ]);
        $this->batch()->insert(new CastingUser, ['id', 'code', 'settings'], [[12, 'c12', ['b' => 2]]]);
        $this->batch()->upsert(new CastingUser, [
            ['code' => 'c10', 'settings' => ['a' => 2]],
            ['code' => 'c13', 'settings' => ['c' => 3]],
        ], ['code']);

        $this->assertSettings(['a' => 2], 10);
        $this->assertSettings(null, 11);
        $this->assertSettings(['b' => 2], 12);
        $this->assertSame(['c' => 3], json_decode(DB::table('users')->where('code', 'c13')->value('settings'), true));
    }

    public function test_string_values_for_json_cast_columns_are_stored_as_given(): void
    {
        $this->batch()->update(new CastingUser, [['id' => 1, 'settings' => '{"raw": true}']]);

        $this->assertSettings(['raw' => true], 1);
    }
}
