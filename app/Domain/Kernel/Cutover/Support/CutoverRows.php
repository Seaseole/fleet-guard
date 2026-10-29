<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Support;

use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\Data\CutoverRowResult;
use App\Domain\Kernel\Cutover\Data\CutoverValidation;

/**
 * Walks a parsed master-data file and lets the dataset decode its own rows.
 *
 * The structural half — size, delimiter, header, row limit — is {@see CutoverCsv}'s. This adds the
 * one rule every dataset shares at row level: a line whose cell count disagrees with the header is
 * an error on that line, not a crash, and the rest of the file is still read so the report can list
 * every problem at once.
 */
final class CutoverRows
{
    /**
     * @param  callable(CutoverCells): CutoverRowResult  $decode
     */
    public static function read(CutoverDataset $dataset, string $contents, callable $decode): CutoverValidation
    {
        $parsed = CutoverCsv::parse($contents, $dataset);

        if ($parsed->table === null) {
            return new CutoverValidation([], $parsed->errors);
        }

        $table = $parsed->table;
        $positions = array_flip($table->headers);
        $width = count($table->headers);
        $rows = [];

        foreach ($table->rows as $input) {
            $structureError = count($input['cells']) === $width
                ? ''
                : sprintf(
                    'Line %d has %d value(s) but the header has %d column(s).',
                    $input['line'],
                    count($input['cells']),
                    $width,
                );

            $rows[] = $decode(new CutoverCells($positions, $input['cells'], $input['line'], $structureError));
        }

        return new CutoverValidation($rows, []);
    }
}
