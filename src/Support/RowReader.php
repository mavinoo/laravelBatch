<?php declare(strict_types=1);

namespace Mavinoo\Batch\Support;

use Generator;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Reads rows one at a time from a CSV / TSV / JSON Lines file, an open stream or any iterable,
 * so files of any size can be imported with constant memory.
 *
 * @internal
 * @phpstan-type ReadRow array{0: int, 1: array<array-key, mixed>|null, 2: string|null}
 */
final class RowReader
{
    private const BOM = "\xEF\xBB\xBF";

    /**
     * Yield [row number, row, error] for every row. A row that can't be read has a null row and
     * an error message instead.
     *
     * Row numbers count from 1 and include a CSV header, so they match the file.
     *
     * @param mixed $source file path, stream resource or iterable of rows
     * @param array{format?: string|null, header?: bool|null, columns?: list<string>|null, delimiter?: string|null, enclosure?: string|null} $options
     * @return Generator<int, ReadRow>
     */
    public static function read(mixed $source, array $options): Generator
    {
        if (is_iterable($source)) {
            return yield from self::readIterable($source);
        }

        $format = $options['format'] ?? (is_string($source) ? self::formatOf($source) : 'csv');

        if (is_string($source)) {
            $stream = self::open($source);

            try {
                return yield from self::readStream($stream, $format, $options);
            } finally {
                fclose($stream);
            }
        }

        if (is_resource($source)) {
            return yield from self::readStream($source, $format, $options);
        }

        throw new InvalidArgumentException('import() needs a file path, an open stream or an iterable of rows.');
    }

    /**
     * The format of a file from its extension: csv, tsv or jsonl. A ".gz" suffix is ignored.
     */
    public static function formatOf(string $path): string
    {
        $name = strtolower(basename($path));
        $name = str_ends_with($name, '.gz') ? substr($name, 0, -3) : $name;

        switch (pathinfo($name, PATHINFO_EXTENSION)) {
            case 'csv':
            case 'txt':
                return 'csv';
            case 'tsv':
            case 'tab':
                return 'tsv';
            case 'jsonl':
            case 'ndjson':
                return 'jsonl';
        }

        throw new InvalidArgumentException("Can't tell the format of \"{$path}\" from its extension. Pass the \"format\" option: csv, tsv or jsonl.");
    }

    /**
     * Open a file for reading; ".gz" files are decompressed on the fly.
     *
     * @return resource
     */
    public static function open(string $path)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("The file \"{$path}\" doesn't exist or can't be read.");
        }

        $stream = @fopen(str_ends_with(strtolower($path), '.gz') ? 'compress.zlib://' . $path : $path, 'rb');

        if ($stream === false) {
            throw new InvalidArgumentException("The file \"{$path}\" can't be opened.");
        }

        return $stream;
    }

    /**
     * @param iterable<mixed> $rows
     * @return Generator<int, ReadRow>
     */
    private static function readIterable(iterable $rows): Generator
    {
        $number = 0;
        foreach ($rows as $row) {
            $number++;

            if ($row instanceof Model) {
                $row = $row->getAttributes();
            } elseif ($row instanceof Arrayable) {
                $row = $row->toArray();
            } elseif (is_object($row)) {
                $row = get_object_vars($row);
            }

            yield is_array($row)
                ? [$number, $row, null]
                : [$number, null, 'it must be an array or an object, ' . get_debug_type($row) . ' given'];
        }
    }

    /**
     * @param resource $stream
     * @param array{header?: bool|null, columns?: list<string>|null, delimiter?: string|null, enclosure?: string|null} $options
     * @return Generator<int, ReadRow>
     */
    private static function readStream($stream, string $format, array $options): Generator
    {
        switch ($format) {
            case 'csv':
            case 'tsv':
                return yield from self::readCsv($stream, $options['delimiter'] ?? ($format === 'tsv' ? "\t" : ','), $options);
            case 'jsonl':
                return yield from self::readJsonLines($stream);
        }

        throw new InvalidArgumentException("Unknown format \"{$format}\". Use csv, tsv or jsonl.");
    }

    /**
     * @param resource $stream
     * @param array{header?: bool|null, columns?: list<string>|null, enclosure?: string|null} $options
     * @return Generator<int, ReadRow>
     */
    private static function readCsv($stream, string $delimiter, array $options): Generator
    {
        $enclosure = $options['enclosure'] ?? '"';
        $columns = $options['columns'] ?? null;
        $hasHeader = $options['header'] ?? is_null($columns);
        $number = 0;
        $first = true;

        // An empty escape character reads files the RFC 4180 way: quotes are escaped by doubling them.
        while (($fields = fgetcsv($stream, null, $delimiter, $enclosure, '')) !== false) {
            $number++;

            if ($first && isset($fields[0]) && str_starts_with((string) $fields[0], self::BOM)) {
                $fields[0] = substr((string) $fields[0], strlen(self::BOM));
            }
            $first = false;

            if ($fields === [null]) {
                continue; // blank line
            }

            if ($hasHeader && $number === 1) {
                $columns ??= self::headerColumns($fields);
                continue;
            }

            if (is_null($columns)) {
                throw new InvalidArgumentException('import() needs a header row or the "columns" option.');
            }

            if (count($fields) !== count($columns)) {
                yield [$number, null, sprintf('it has %d fields, but there are %d columns', count($fields), count($columns))];
                continue;
            }

            yield [$number, array_combine($columns, $fields), null];
        }
    }

    /**
     * @param array<int, string|null> $fields
     * @return list<string>
     */
    private static function headerColumns(array $fields): array
    {
        $columns = array_map(fn ($field) => trim((string) $field), array_values($fields));

        if (count(array_unique($columns)) !== count($columns)) {
            throw new InvalidArgumentException('The header row has duplicate column names: ' . implode(', ', $columns));
        }

        return $columns;
    }

    /**
     * @param resource $stream
     * @return Generator<int, ReadRow>
     */
    private static function readJsonLines($stream): Generator
    {
        $number = 0;
        while (($line = fgets($stream)) !== false) {
            $number++;

            if ($number === 1 && str_starts_with($line, self::BOM)) {
                $line = substr($line, strlen(self::BOM));
            }

            if (trim($line) === '') {
                continue;
            }

            $row = json_decode($line, true);

            if (!is_array($row) || array_is_list($row) && $row !== []) {
                yield [$number, null, json_last_error() !== JSON_ERROR_NONE ? 'it is not valid JSON: ' . json_last_error_msg() : 'it must be a JSON object'];
                continue;
            }

            yield [$number, $row, null];
        }
    }
}
