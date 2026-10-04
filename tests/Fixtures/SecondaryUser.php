<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Fixtures;

class SecondaryUser extends User
{
    protected $connection = 'secondary';
}
