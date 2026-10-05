<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Fixtures;

use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;

class CastingUser extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'meta' => AsCollection::class,
        'balance' => 'integer',
    ];
}
