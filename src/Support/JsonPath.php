<?php declare(strict_types=1);

namespace Mavinoo\Batch\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * Compiles "column->key->key" updates into the JSON functions of each database.
 *
 * Paths and values are always sent as bound parameters.
 *
 * @internal
 * @phpstan-type Statement array{0: string, 1: list<mixed>}
 * @phpstan-type Path array{0: list<string>, 1: mixed}
 */
final class JsonPath
{
    private const ENCODE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * Split "column->a->b" into the column and its keys, or return null for a plain column.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public static function parse(string $key): ?array
    {
        if (!str_contains($key, '->')) {
            return null;
        }

        $keys = explode('->', $key);
        $column = array_shift($keys);

        foreach ([$column, ...$keys] as $part) {
            if ($part === '' || strpbrk($part, '"\\') !== false) {
                throw new InvalidArgumentException(
                    "Invalid JSON path \"{$key}\": the column and keys can't be empty or contain quotes or backslashes."
                );
            }
        }

        return [$column, $keys];
    }

    /**
     * The new value of a JSON column after setting each path, as an SQL expression.
     *
     * A NULL column starts as an empty object, and missing parent objects are created.
     *
     * @param list<Path> $paths
     * @return Statement
     */
    public static function compileSet(Grammar $grammar, string $driver, string $column, array $paths): array
    {
        $wrapped = $grammar->wrap($column);
        $parents = self::parents($paths);

        switch ($driver) {
            case 'mysql':
            case 'mariadb':
                $args = ['COALESCE(' . $wrapped . ', JSON_OBJECT())'];
                $bindings = [];

                foreach ($parents as $keys) {
                    $args[] = '?';
                    $args[] = 'COALESCE(JSON_EXTRACT(' . $wrapped . ', ?), JSON_OBJECT())';
                    array_push($bindings, self::textPath($keys), self::textPath($keys));
                }

                foreach ($paths as [$keys, $value]) {
                    [$valueSql, $valueBindings] = self::compileValue($grammar, $driver, $value);
                    $args[] = '?';
                    $args[] = $valueSql;
                    array_push($bindings, self::textPath($keys), ...$valueBindings);
                }

                return ['JSON_SET(' . implode(', ', $args) . ')', $bindings];

            case 'sqlite':
                // SQLite's json_set() creates missing parent objects itself.
                $args = ['COALESCE(' . $wrapped . ', json_object())'];
                $bindings = [];

                foreach ($paths as [$keys, $value]) {
                    [$valueSql, $valueBindings] = self::compileValue($grammar, $driver, $value);
                    $args[] = '?';
                    $args[] = $valueSql;
                    array_push($bindings, self::textPath($keys), ...$valueBindings);
                }

                return ['json_set(' . implode(', ', $args) . ')', $bindings];

            case 'pgsql':
                $sql = 'COALESCE(CAST(' . $wrapped . " AS jsonb), '{}'::jsonb)";
                $bindings = [];

                foreach ($parents as $keys) {
                    [$pathSql, $pathBindings] = self::arrayPath($keys);
                    $sql = 'jsonb_set(' . $sql . ', ' . $pathSql . ', COALESCE(CAST(' . $wrapped . ' AS jsonb) #> ' . $pathSql . ", '{}'::jsonb))";
                    array_push($bindings, ...$pathBindings, ...$pathBindings);
                }

                foreach ($paths as [$keys, $value]) {
                    [$pathSql, $pathBindings] = self::arrayPath($keys);
                    [$valueSql, $valueBindings] = self::compileValue($grammar, $driver, $value);
                    $sql = 'jsonb_set(' . $sql . ', ' . $pathSql . ', ' . $valueSql . ')';
                    array_push($bindings, ...$pathBindings, ...$valueBindings);
                }

                return [$sql, $bindings];
        }

        throw new InvalidArgumentException("JSON path updates are not supported on the \"{$driver}\" driver.");
    }

    /**
     * A condition that is true when $new differs from the column's current JSON value.
     *
     * @param Statement $new
     * @return Statement
     */
    public static function compileChanged(Grammar $grammar, string $driver, string $column, array $new): array
    {
        $wrapped = $grammar->wrap($column);

        switch ($driver) {
            case 'pgsql':
                return ['CAST(' . $wrapped . ' AS jsonb) IS DISTINCT FROM ' . $new[0], $new[1]];
            case 'sqlite':
                return ['json(' . $wrapped . ') IS NOT ' . $new[0], $new[1]];
            default:
                return ['NOT (' . $wrapped . ' <=> ' . $new[0] . ')', $new[1]];
        }
    }

    /**
     * A value to store at a path: PHP values are sent as JSON, DB::raw() expressions as given.
     *
     * @return Statement
     */
    private static function compileValue(Grammar $grammar, string $driver, mixed $value): array
    {
        if ($value instanceof Expression) {
            return [(string) $grammar->getValue($value), []];
        }

        $json = json_encode($value, self::ENCODE_FLAGS);

        switch ($driver) {
            case 'pgsql':
                return ['CAST(? AS jsonb)', [$json]];
            case 'sqlite':
                return ['json(?)', [$json]];
            default:
                return ['JSON_EXTRACT(?, \'$\')', [$json]];
        }
    }

    /**
     * Every parent of the given paths, shallowest first, so they can be created in order.
     *
     * @param list<Path> $paths
     * @return list<list<string>>
     */
    private static function parents(array $paths): array
    {
        $parents = [];
        foreach ($paths as [$keys]) {
            for ($depth = 1; $depth < count($keys); $depth++) {
                $parent = array_slice($keys, 0, $depth);
                $parents[implode('->', $parent)] = $parent;
            }
        }

        $parents = array_values($parents);
        usort($parents, fn (array $a, array $b) => count($a) <=> count($b));

        return $parents;
    }

    /**
     * A MySQL / SQLite path such as $."a"."b".
     *
     * @param list<string> $keys
     */
    private static function textPath(array $keys): string
    {
        return '$' . implode('', array_map(fn (string $key) => '."' . $key . '"', $keys));
    }

    /**
     * A PostgreSQL text[] path such as ARRAY[?, ?]::text[].
     *
     * @param list<string> $keys
     * @return Statement
     */
    private static function arrayPath(array $keys): array
    {
        return ['ARRAY[' . implode(', ', array_fill(0, count($keys), '?')) . ']::text[]', $keys];
    }
}
