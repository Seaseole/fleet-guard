<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover;

/**
 * The batch's states (§15.2: validate, preview, then post or apply).
 *
 * `rejected` is terminal on purpose: a file that failed validation is corrected and uploaded
 * again, which leaves the first attempt and its error report intact. Nothing re-validates in
 * place, because "the batch my accountant saw" must keep meaning the same rows.
 *
 * `posted` is the opening trial balance's outcome and `applied` is every master-data dataset's.
 * An applied batch is terminal too: master rows are corrected on their own screens afterwards, and
 * the batch stays as the record of what the file put there. Re-uploading the same file is an
 * update by natural key, not a second application.
 */
enum CutoverBatchStatus: string
{
    case Validated = 'validated';
    case Rejected = 'rejected';
    case Posted = 'posted';
    case Reversed = 'reversed';
    case Applied = 'applied';

    public function label(): string
    {
        return match ($this) {
            self::Validated => 'Validated — ready for the Owner',
            self::Rejected => 'Rejected — fix the file',
            self::Posted => 'Posted to the ledger',
            self::Reversed => 'Reversed',
            self::Applied => 'Applied to master data',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Rejected || $this === self::Reversed || $this === self::Applied;
    }
}
