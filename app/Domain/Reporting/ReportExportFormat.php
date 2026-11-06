<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * The three shapes a report leaves the building in (PDF/Excel exports queued for large
 * ranges; CSV because simple-excel streams it and a dispatcher with LibreOffice will take it).
 */
enum ReportExportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Pdf = 'pdf';

    public function extension(): string
    {
        return $this->value;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
