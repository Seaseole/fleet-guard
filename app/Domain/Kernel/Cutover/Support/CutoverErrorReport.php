<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Support;

use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Money\Money;

/**
 * The error report — the file the accountant works from.
 *
 * Batch errors get line 0 because they belong to no row; row errors keep their physical line
 * number. A trial balance prints its ledger columns; every other dataset prints the columns its
 * file carried, with the two secret inputs replaced by their masks. Cells that could be read as a
 * spreadsheet formula are prefixed with an apostrophe, so a description beginning with "=" arrives
 * as text rather than executing on the machine that opens the report.
 */
final class CutoverErrorReport
{
    public static function filename(CutoverBatch $batch): string
    {
        return sprintf('cutover-errors-batch-%d-%s.csv', $batch->id, now()->format('Ymd-His'));
    }

    public static function build(CutoverBatch $batch): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        $batch->dataset === CutoverDataset::OpeningTrialBalance
            ? self::trialBalance($handle, $batch)
            : self::masterData($handle, $batch);

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** @param resource $handle */
    private static function trialBalance($handle, CutoverBatch $batch): void
    {
        fputcsv($handle, ['line', 'account_code', 'account_name', 'debit', 'credit', 'description', 'errors'], ',', '"', '');

        foreach ($batch->errors() as $error) {
            fputcsv($handle, [0, '', '', '', '', '', $error], ',', '"', '');
        }

        foreach ($batch->rows()->where('status', 'error')->get() as $row) {
            fputcsv($handle, [
                $row->line_number,
                self::safe((string) $row->account_code),
                self::safe((string) $row->account_name),
                self::amount($row->debit_minor, $batch->currency_code),
                self::amount($row->credit_minor, $batch->currency_code),
                self::safe((string) $row->description),
                implode(' ', $row->errorList()),
            ], ',', '"', '');
        }
    }

    /** @param resource $handle */
    private static function masterData($handle, CutoverBatch $batch): void
    {
        $columns = $batch->dataset->previewColumns();

        fputcsv($handle, ['line', ...$columns, 'errors'], ',', '"', '');

        foreach ($batch->errors() as $error) {
            fputcsv($handle, [0, ...array_fill(0, count($columns), ''), $error], ',', '"', '');
        }

        foreach ($batch->rows()->where('status', 'error')->get() as $row) {
            $payload = $row->payload ?? [];

            $cells = array_map(
                static fn (string $column): string => self::masterCell($payload, $column, $batch),
                $columns,
            );

            fputcsv($handle, [$row->line_number, ...$cells, implode(' ', $row->errorList())], ',', '"', '');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function masterCell(array $payload, string $column, CutoverBatch $batch): string
    {
        $value = $payload[$column] ?? null;

        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_int($value) && in_array($column, $batch->dataset->moneyColumns(), true)) {
            $rowCurrency = $payload['currency_code'] ?? null;

            return (string) Money::ofMinor(
                $value,
                is_string($rowCurrency) && $rowCurrency !== '' ? $rowCurrency : $batch->currency_code,
            )->toBigDecimal();
        }

        return self::safe((string) $value);
    }

    /** README rule: a leading =, +, - or @ is a formula to Excel, not text. */
    private static function safe(string $cell): string
    {
        return $cell !== '' && str_contains('=+-@', $cell[0]) ? "'".$cell : $cell;
    }

    private static function amount(int $minor, string $currencyCode): string
    {
        return $minor === 0 ? '' : (string) Money::ofMinor($minor, $currencyCode)->toBigDecimal();
    }
}
