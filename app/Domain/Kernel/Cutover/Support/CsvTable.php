<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Support;

/**
 * A parsed tabular upload. Delimiter detection, BOM and UTF-16 handling belong here because the
 * files come from an accountant's spreadsheet export, not from our own writer.
 *
 * Parsing stops at structure: it reports the header and the physical line number of every row and
 * says nothing about whether a row is valid. That answer belongs to the dataset's validator, which
 * is the part that will differ when Phase 1 adds master-data importers.
 */
final class CsvTable
{
    public const MAX_COLUMNS = 40;

    /**
     * @param  list<string>  $headers  normalised to lower snake case
     * @param  list<array{line: int, cells: list<string>}>  $rows
     */
    private function __construct(
        public readonly array $headers,
        public readonly array $rows,
        public readonly ?string $error = null,
    ) {}

    public static function parse(string $contents): self
    {
        $contents = self::toUtf8($contents);

        if (trim($contents) === '') {
            return new self([], [], 'The file is empty.');
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return new self([], [], 'The file could not be read.');
        }

        fwrite($handle, $contents);
        rewind($handle);

        $delimiter = self::detectDelimiter($contents);

        $headers = null;
        $rows = [];
        $line = 0;

        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $line++;
            $cells = array_map(static fn (mixed $cell): string => trim((string) $cell), $cells);

            if (self::isBlank($cells)) {
                continue;
            }

            if ($headers === null) {
                $headers = array_map(self::normaliseHeader(...), $cells);

                if (count($headers) > self::MAX_COLUMNS) {
                    fclose($handle);

                    return new self([], [], 'The file has more than '.self::MAX_COLUMNS.' columns.');
                }

                continue;
            }

            $rows[] = ['line' => $line, 'cells' => $cells];
        }

        fclose($handle);

        if ($headers === null) {
            return new self([], [], 'The file has no header row.');
        }

        return new self($headers, $rows);
    }

    public static function normaliseHeader(string $header): string
    {
        $header = strtolower(trim(preg_replace('/[\s\-]+/', '_', $header) ?? $header));

        return trim($header, '_');
    }

    /** @param  list<string>  $cells */
    private static function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== '') {
                return false;
            }
        }

        return true;
    }

    private static function detectDelimiter(string $contents): string
    {
        $firstLine = strtok($contents, "\r\n");

        if ($firstLine === false) {
            return ',';
        }

        $scores = [
            ',' => substr_count($firstLine, ','),
            ';' => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
        ];

        arsort($scores);
        $delimiter = array_key_first($scores);

        return $scores[$delimiter] > 0 ? (string) $delimiter : ',';
    }

    /**
     * Excel on a Windows box writes UTF-16LE when the user picks "Unicode Text"; saving as CSV
     * usually writes UTF-8 with a BOM. Accepting both costs four lines and removes the most
     * common "the file is unreadable" support call.
     */
    private static function toUtf8(string $contents): string
    {
        if (str_starts_with($contents, "\xFF\xFE")) {
            $contents = (string) mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($contents, "\xFE\xFF")) {
            $contents = (string) mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16BE');
        }

        return ltrim($contents, "\xEF\xBB\xBF");
    }
}
