<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exporters;

use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Reporting\Data\ReportData;
use App\Domain\Reporting\ReportExportFormat;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * ReportData to bytes, in one of the three shapes (heavy reports and exports are queued
 * work — this class is only ever called from the job, never from a request).
 *
 * The spreadsheet writers stream to a temporary file through simple-excel (openspout under the
 * hood, row by row, which is what the "a 12-month export completes without a memory
 * error" acceptance is actually testing); the PDF goes through the same DOMPDF driver the
 * invoices already use, so there is one rendering stack in the building.
 */
final class ReportFileWriter
{
    /**
     * @return array{bytes: string, mime: string}
     */
    public function write(ReportData $data, ReportExportFormat $format): array
    {
        return match ($format) {
            ReportExportFormat::Csv => ['bytes' => $this->spreadsheet($data, 'csv'), 'mime' => 'text/csv; charset=UTF-8'],
            ReportExportFormat::Xlsx => ['bytes' => $this->spreadsheet($data, 'xlsx'), 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ReportExportFormat::Pdf => ['bytes' => $this->pdf($data), 'mime' => 'application/pdf'],
        };
    }

    private function spreadsheet(ReportData $data, string $type): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'fre-'.$type.'-').'.'.$type;

        try {
            $writer = SimpleExcelWriter::create($temp, $type);

            // addHeader, not a first addRow: v3 writes the keys of the first bare array as a
            // header line, which would print `0,1,2,3` where the column names belong.
            $writer->addHeader(array_map(static fn (array $column): string => $column['label'], $data->columns));

            foreach ($data->rows as $row) {
                $writer->addRow($row);
            }

            if ($data->totals !== null) {
                $writer->addRow($data->totals);
            }

            if ($data->note !== null) {
                $writer->addRow([$data->note]);
            }

            // close(), not the destructor: openspout only writes the zip container on close,
            // and reading the temp file before that returns an empty body with no error.
            $writer->close();

            return (string) file_get_contents($temp);
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    private function pdf(ReportData $data): string
    {
        return Pdf::driver('dompdf')
            ->view('pdf.report', [
                'data' => $data,
                'company' => CurrentCompany::model(),
                'kind' => 'Report',
                'number' => $data->title,
                'issuedOn' => now()->format('d M Y H:i'),
            ])
            ->format('A4')
            ->margins(18, 16, 20, 16)
            ->generatePdfContent();
    }
}
