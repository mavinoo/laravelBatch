<?php declare(strict_types=1);

namespace Mavinoo\Batch\Tests\Fixtures;

use Mavinoo\Batch\Batch;

/**
 * Batch with a tiny bound-parameter limit, to exercise query splitting on every driver.
 */
class SmallBatch extends Batch
{
    protected const MAX_BINDINGS = [];

    protected const DEFAULT_MAX_BINDINGS = 40;
}
