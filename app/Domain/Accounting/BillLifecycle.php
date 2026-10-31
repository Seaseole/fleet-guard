<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * §10.9 — the supplier bill's lifecycle, and the only bill axis that is stored.
 *
 * `partially_paid` and `paid` are deliberately not cases here: settlement is derived from the
 * payment rows the way an invoice's settlement is (§10.4), and a stored `paid` would let the
 * payment slice mutate lifecycle state — which is where gap-free numbering and audit semantics
 * quietly die.
 *
 * A bill is numbered at creation (`BILL-`, continuous, like `WO-`): it is the *supplier's* fiscal
 * document, not ours, so our own reference needs "never reused" rather than §7.4's
 * "gap-free at issue". `draft` is therefore not a state without a number, unlike an invoice's.
 */
enum BillLifecycle: string
{
    case Draft = 'draft';
    case Recorded = 'recorded';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Recorded => 'Recorded',
            self::Void => 'Void',
        };
    }

    /** A draft may still be edited or discarded; a recorded bill is a ledger fact, corrected only by a void. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** The states that count as owed money — a void bill owes nothing. */
    public function isOpenItem(): bool
    {
        return $this === self::Recorded;
    }
}
