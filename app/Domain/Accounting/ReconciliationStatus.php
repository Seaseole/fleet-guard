<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * The lifecycle of a bank reconciliation (Phase 5, slice 5d).
 *
 * `open → approved` is the accountant's signature — refused unless statement and book agree to
 * the minor unit. `open → void` discards an abandoned attempt; `approved → void` is how a wrong
 * signature is corrected: the voided run keeps its items for the audit trail and a fresh run
 * takes the day (the live-row rule — the unique day index counts only non-void rows).
 * Nothing edits an approved run in place, for the same reason the ledger has no UPDATE.
 */
enum ReconciliationStatus: string
{
    case Open = 'open';
    case Approved = 'approved';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Approved => 'Approved',
            self::Void => 'Void',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Open;
    }
}
