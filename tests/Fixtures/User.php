<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Mavinoo\Batch\Traits\HasBatch;

class User extends Model
{
    use HasBatch;

    protected $table = 'users';

    protected $guarded = [];
}
