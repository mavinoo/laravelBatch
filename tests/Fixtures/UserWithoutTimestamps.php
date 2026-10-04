<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Fixtures;

class UserWithoutTimestamps extends User
{
    public $timestamps = false;
}
