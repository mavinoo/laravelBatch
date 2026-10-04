<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;

class SoftDeletingUser extends User
{
    use SoftDeletes;
}
