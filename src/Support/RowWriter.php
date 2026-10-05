<?php declare(strict_types=1);

namespace Mavinoo\Batch\Support;

use InvalidArgumentException;

/**
 * Writes rows to a CSV / TSV / JSON Lines file (also gzipped), starting a new part every
 * $maxRows rows. Used by Batch::export().
 *
 * @internal
 */
final class RowWriter
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /** @var resource|null */
    private $stream = null;

    /** @var list<string> */
    private array $files = [];

    /** @var list<string>|null */
    private ?array $columns = null;

    private int $rowsInFile = 0;

    private string $format;

    private bool $gzip;

    public function __construct(
        private string $path,
        ?string $format,
        private ?int $maxRows,
        private ?bool $header,
        private ?string $delimiter,
        private string $enclosure,
        private bool $overwrite,
        private string $dateFormat,
    ) {
        $this->format = $format ?? RowReader::formatOf($path);
        $this->gzip = str_ends_with(strtolower($path), '.gz');

        if (!in_array($this->format, ['csv', 'tsv', 'jsonl'], true)) {
            throw new InvalidArgumentException("Unknown format \"{$this->format}\". Use csv, tsv or jsonl.");
        }

        if (!is_null($maxRows) && $maxRows < 1) {
            throw new InvalidArgumentException('The export "maxRows" option must be a positive integer.');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public function write(array $row): void
    {
        if ($this->stream && !is_null($this->maxRows) && $this->rowsInFile >= $this->maxRows) {
            $this->closeStream();
        }

        if (!$this->stream) {
            $this->open();
        }

        $stream = $this->stream;
        assert(is_resource($stream));

        if ($this->format === 'jsonl') {
            fwrite($stream, json_encode($row, self::JSON_FLAGS) . "\n");
        } else {
            $columns = $this->columns ??= array_keys($row);

            if ($this->rowsInFile === 0 && ($this->header ?? true)) {
                $this->csvLine($stream, $columns);
            }

            $fields = [];
            foreach ($columns as $column) {
                $fields[] = $this->csvValue($row[$column] ?? null);
            }
            $this->csvLine($stream, $fields);
        }

        $this->rowsInFile++;
    }

    /**
     * Close the last file and return every file written, in order.
     *
     * @return list<string>
     */
    public function close(): array
    {
        $this->closeStream();

        return $this->files;
    }

    private function open(): void
    {
        $target = $this->path;

        if (!is_null($this->maxRows)) {
            [$directory, $base, $extension] = FileSplitter::nameParts($this->path, null);
            $target = sprintf('%s/%s-%03d%s%s', $directory, $base, count($this->files) + 1, $extension, $this->gzip ? '.gz' : '');
        } elseif (!is_dir(dirname($target)) || !is_writable(dirname($target))) {
            throw new InvalidArgumentException('The directory "' . dirname($target) . "\" doesn't exist or can't be written.");
        }

        if (!$this->overwrite && file_exists($target)) {
            throw new InvalidArgumentException("The file \"{$target}\" already exists. Pass \"overwrite\" => true to replace it.");
        }

        $stream = @fopen($this->gzip ? 'compress.zlib://' . $target : $target, 'wb');

        if ($stream === false) {
            throw new InvalidArgumentException("The file \"{$target}\" can't be written.");
        }

        $this->stream = $stream;
        $this->files[] = $target;
        $this->rowsInFile = 0;
    }

    private function closeStream(): void
    {
        if ($this->stream) {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    /**
     * @param resource $stream
     * @param list<string> $fields
     */
    private function csvLine($stream, array $fields): void
    {
        $delimiter = $this->delimiter ?? ($this->format === 'tsv' ? "\t" : ',');

        // An empty escape character writes the RFC 4180 way: quotes are escaped by doubling them.
        fputcsv($stream, $fields, $delimiter, $this->enclosure, '', "\n");
    }

    private function csvValue(mixed $value): string
    {
        if (is_null($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format($this->dateFormat);
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return json_encode($value, self::JSON_FLAGS);
    }
}
