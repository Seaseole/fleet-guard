<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Support;

use App\Domain\Kernel\Cutover\Models\CutoverRow;

/**
 * Turns validated batch rows into the flat line arrays the ledger's opening-balance Action takes.
 *
 * The line note carries the spreadsheet line so a reviewer reading the journal can find the row
 * that produced it. Valid rows are one-sided by construction (the table's CHECK), so the direction
 * is read off whichever amount is non-zero.
 */
final class BatchPostingLines
{
    /**
     * @param  iterable<CutoverRow>  $rows
     * @return list<array{account_id: int, direction: string, amount: int, note: string}>
     */
    public static function fromRows(iterable $rows): array
    {
        $lines = [];

        foreach ($rows as $row) {
            $isDebit = $row->debit_minor > 0;
            $note = $row->description === null
                ? "Line {$row->line_number}"
                : "Line {$row->line_number}: {$row->description}";

            $lines[] = [
                'account_id' => (int) $row->gl_account_id,
                'direction' => $isDebit ? 'debit' : 'credit',
                'amount' => $isDebit ? $row->debit_minor : $row->credit_minor,
                'note' => $note,
            ];
        }

        return $lines;
    }
}
