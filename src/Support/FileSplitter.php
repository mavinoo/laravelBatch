<?php declare(strict_types=1);

namespace Mavinoo\Batch\Support;

use InvalidArgumentException;

/**
 * Splits a large file into parts by number of records and/or size, without ever cutting a
 * record in half. Records are copied byte for byte.
 *
 * @internal
 */
final class FileSplitter
{
    /**
     * @param int|null $lines records per part, not counting the header
     * @param int|null $bytes maximum bytes per part, header included; a bigger record gets a part of its own
     * @param array{header?: bool|null, format?: string|null, directory?: string|null, overwrite?: bool|null, enclosure?: string|null} $options
     * @return list<string> paths of the parts, in order
     */
    public static function split(string $path, ?int $lines, ?int $bytes, array $options = []): array
    {
        if (is_null($lines) && is_null($bytes)) {
            throw new InvalidArgumentException('splitFile() needs a number of lines, a number of bytes, or both.');
        }

        if ((!is_null($lines) && $lines < 1) || (!is_null($bytes) && $bytes < 1)) {
            throw new InvalidArgumentException('splitFile() needs a positive number of lines or bytes.');
        }

        $format = $options['format'] ?? self::formatOf($path);
        $csv = in_array($format, ['csv', 'tsv'], true);
        $enclosure = $options['enclosure'] ?? '"';
        $hasHeader = $options['header'] ?? $csv;

        [$directory, $base, $extension] = self::nameParts($path, $options['directory'] ?? null);
        $overwrite = $options['overwrite'] ?? false;

        $input = RowReader::open($path);
        $parts = [];
        $output = null;
        $partRecords = 0;
        $partBytes = 0;
        $header = '';

        try {
            if ($hasHeader) {
                $header = self::readRecord($input, $csv, $enclosure) ?? '';
                if ($header !== '' && !preg_match('/\R$/', $header)) {
                    $header .= "\n"; // a file with only a header and no newline
                }
            }

            while (!is_null($record = self::readRecord($input, $csv, $enclosure))) {
                $size = strlen($record);
                $full = $output && (
                    (!is_null($lines) && $partRecords >= $lines)
                    || (!is_null($bytes) && $partBytes + $size > $bytes)
                );

                if (!$output || $full) {
                    if ($output) {
                        fclose($output);
                    }

                    $target = sprintf('%s/%s-%03d%s', $directory, $base, count($parts) + 1, $extension);
                    if (!$overwrite && file_exists($target)) {
                        throw new InvalidArgumentException("The file \"{$target}\" already exists. Pass \"overwrite\" => true to replace it.");
                    }

                    $output = @fopen($target, 'wb');
                    if ($output === false) {
                        throw new InvalidArgumentException("The file \"{$target}\" can't be written.");
                    }

                    $parts[] = $target;
                    fwrite($output, $header);
                    $partRecords = 0;
                    $partBytes = strlen($header);
                }

                fwrite($output, $record);
                $partRecords++;
                $partBytes += $size;
            }
        } finally {
            fclose($input);
            if ($output) {
                fclose($output);
            }
        }

        return $parts;
    }

    /**
     * Read one record with its line ending: a line, or for CSV as many lines as a quoted field spans.
     *
     * @param resource $input
     */
    private static function readRecord($input, bool $csv, string $enclosure): ?string
    {
        $record = fgets($input);

        if ($record === false) {
            return null;
        }

        // An odd number of quote characters means a quoted field continues on the next line.
        while ($csv && substr_count($record, $enclosure) % 2 === 1 && ($next = fgets($input)) !== false) {
            $record .= $next;
        }

        return $record;
    }

    private static function formatOf(string $path): string
    {
        try {
            return RowReader::formatOf($path);
        } catch (InvalidArgumentException $e) {
            return 'lines'; // any other text file is split on lines
        }
    }

    /**
     * Where the parts go and how they're named: "users.csv.gz" becomes "users-001.csv".
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function nameParts(string $path, ?string $directory): array
    {
        $name = basename($path);
        if (str_ends_with(strtolower($name), '.gz')) {
            $name = substr($name, 0, -3);
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
        $directory = rtrim($directory ?? dirname($path), '/\\');

        if (!is_dir($directory) || !is_writable($directory)) {
            throw new InvalidArgumentException("The directory \"{$directory}\" doesn't exist or can't be written.");
        }

        return [$directory, $base, $extension === '' ? '' : '.' . $extension];
    }
}
