<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Mavinoo\Batch\Tests\Fixtures\SoftDeletingUser;
use Mavinoo\Batch\Tests\Fixtures\User;
use Mavinoo\Batch\Tests\TestCase;

/**
 * Laravel's Model::withoutTimestamps() turns timestamps off for every Batch method.
 */
class WithoutTimestampsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedUsers(4);
    }

    public function test_every_method_leaves_timestamps_alone(): void
    {
        User::withoutTimestamps(function () {
            $this->batch()->update(new User, [['id' => 1, 'name' => 'changed']]);
            $this->batch()->updateMultipleCondition(new User, [['conditions' => ['id' => 2], 'columns' => ['name' => 'changed']]]);
            $this->batch()->insertRows(new User, [['code' => 'new1']]);
            $this->batch()->insertGetIds(new User, [['code' => 'new2']]);
            $this->batch()->upsert(new User, [['code' => 'c3', 'name' => 'changed'], ['code' => 'new3', 'name' => 'new']], ['code']);
            $this->batch()->upsert(new User, [['code' => 'c3', 'balance' => 1]], ['code'], ['balance' => ['+']]);
            $this->batch()->updateInChunks(User::where('id', 4), ['name' => 'changed']);
        });

        foreach ([1, 2, 3, 4] as $id) {
            $this->assertSame('changed', $this->row($id)->name);
            $this->assertSame(self::OLD, (string) $this->row($id)->updated_at, "row {$id}");
        }
        $this->assertSame(101, (int) $this->row(3)->balance);

        foreach (['new1', 'new2', 'new3'] as $code) {
            $row = DB::table('users')->where('code', $code)->first();
            $this->assertNull($row->created_at, $code);
            $this->assertNull($row->updated_at, $code);
        }
    }

    public function test_soft_deletes_leave_updated_at_alone(): void
    {
        SoftDeletingUser::withoutTimestamps(function () {
            $this->batch()->deleteByKeys(new SoftDeletingUser, [['id' => 1]], ['id']);
        });

        $this->assertNotNull($this->row(1)->deleted_at);
        $this->assertSame(self::OLD, (string) $this->row(1)->updated_at);
    }

    public function test_timestamps_are_back_after_the_callback(): void
    {
        User::withoutTimestamps(fn () => null);

        $this->batch()->update(new User, [['id' => 1, 'name' => 'changed']]);

        $this->assertSame(self::NOW, (string) $this->row(1)->updated_at);
    }
}
