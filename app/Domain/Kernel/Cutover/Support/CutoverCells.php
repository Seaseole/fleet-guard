<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Support;

use App\Domain\Kernel\Cutover\Data\CutoverRowResult;

/**
 * One row's cells, addressed by the header the accountant wrote.
 *
 * A missing optional column reads as blank rather than throwing: the file may legitimately omit it,
 * and every importer treats blank as "leave as is". Only a column the header *required* is absent,
 * which the structural check has already refused before a row is built.
 */
final readonly class CutoverCells
{
    /**
     * @param  array<string, int>  $positions
     * @param  list<string>  $cells
     */
    public function __construct(
        private array $positions,
        private array $cells,
        public int $line,
        private string $structureError = '',
    ) {}

    public function get(string $column): string
    {
        $index = $this->positions[$column] ?? null;

        return $index === null ? '' : ($this->cells[$index] ?? '');
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $errors
     */
    public function result(array $values, array $errors): CutoverRowResult
    {
        return new CutoverRowResult(
            lineNumber: $this->line,
            values: $values,
            errors: $this->structureError === '' ? $errors : [$this->structureError, ...$errors],
        );
    }
}
