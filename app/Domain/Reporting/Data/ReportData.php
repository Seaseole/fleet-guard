<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Data;

/**
 * What a report is: a title, a window named in its own words, columns, and rows already rendered
 * to strings (the ReportDefinitions output).
 *
 * Cells are strings because the three consumers — the screen, the queued export, and the
 * scheduled email — must print the same figures, not each re-decide rounding or the dash for
 * nothing. The gate ran before this object was built; whatever is in it is what this seat is
 * allowed to read (an export is an authorised read, not a side door).
 */
final class ReportData
{
    /**
     * @param  list<array{label: string, numeric: bool}>  $columns
     * @param  list<list<string>>  $rows
     * @param  list<string>|null  $totals
     */
    public function __construct(
        public readonly string $title,
        public readonly string $window,
        public readonly array $columns,
        public readonly array $rows,
        public readonly ?array $totals = null,
        public readonly ?string $note = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
