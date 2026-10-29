<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Support;

use App\Domain\Kernel\Cutover\CutoverDataset;

/**
 * The structural half of an upload, shared by every dataset validator.
 *
 * Size, parse, header and row-count rules belong to the engine, not to a dataset: an importer that
 * forgot the 5 000-row limit would be a memory problem discovered in production. What a dataset
 * owns is what a row *means*, which is why this class stops at "here is a table the headers match".
 */
final class CutoverCsv
{
    public const MAX_ROWS = 5000;

    public const MAX_BYTES = 2_097_152;

    public static function parse(string $contents, CutoverDataset $dataset): CutoverCsvParse
    {
        if (strlen($contents) > self::MAX_BYTES) {
            return new CutoverCsvParse(null, [
                'The file is larger than 2 MB. Split the file or remove unused columns.',
            ]);
        }

        $table = CsvTable::parse($contents);

        if ($table->error !== null) {
            return new CutoverCsvParse(null, [$table->error]);
        }

        $headerErrors = self::headerErrors($table, $dataset);

        if ($headerErrors !== []) {
            return new CutoverCsvParse(null, $headerErrors);
        }

        if (count($table->rows) > self::MAX_ROWS) {
            return new CutoverCsvParse(null, [
                sprintf('The file has %d data rows; the limit is %d.', count($table->rows), self::MAX_ROWS),
            ]);
        }

        if ($table->rows === []) {
            return new CutoverCsvParse(null, ['The file has no data rows below the header.']);
        }

        return new CutoverCsvParse($table, []);
    }

    /** @return list<string> */
    private static function headerErrors(CsvTable $table, CutoverDataset $dataset): array
    {
        $allowed = $dataset->columns();
        $unknown = array_values(array_diff($table->headers, $allowed));
        $missing = array_values(array_diff($dataset->requiredColumns(), $table->headers));
        $duplicated = array_keys(array_filter(
            array_count_values($table->headers),
            static fn (int $count): bool => $count > 1,
        ));

        $errors = [];

        if ($unknown !== []) {
            $errors[] = sprintf(
                'Unexpected column(s): %s. Expected %s.',
                implode(', ', array_map(static fn (string $name): string => $name === '' ? '(blank)' : $name, $unknown)),
                implode(', ', $allowed),
            );
        }

        if ($missing !== []) {
            $errors[] = sprintf('Missing required column(s): %s.', implode(', ', $missing));
        }

        if ($duplicated !== []) {
            $errors[] = sprintf('The header repeats column(s): %s.', implode(', ', $duplicated));
        }

        return $errors;
    }
}
