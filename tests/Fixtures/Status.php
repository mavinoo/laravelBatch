<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Fixtures;

enum Status: string
{
    case Active = 'active';
    case Blocked = 'blocked';
}
