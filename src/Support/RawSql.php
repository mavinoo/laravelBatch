<?php declare(strict_types=1);

namespace Mavinoo\Batch\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * SQL that Batch built itself from wrapped identifiers, used where Laravel takes a raw expression.
 * It never holds values: those are always bound.
 *
 * @internal
 */
final class RawSql implements Expression
{
    public function __construct(private string $sql)
    {
    }

    /**
     * @param Grammar $grammar
     */
    public function getValue(Grammar $grammar): string
    {
        return $this->sql;
    }
}
