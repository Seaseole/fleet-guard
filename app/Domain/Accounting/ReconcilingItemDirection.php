<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * Which way a reconciling item moves the book toward the statement
 * (Phase 5's formula: `statement = book + Σ add − Σ sub`).
 *
 * Kept as data rather than folded into the kind because the same kind legitimately runs both
 * directions in real life — a book error is as often an overstate as an understate — and a
 * sign that lives only in a person's memory of convention is how a reconciliation "agrees"
 * by accident.
 */
enum ReconcilingItemDirection: string
{
    case Add = 'add';
    case Subtract = 'sub';

    public function sign(): int
    {
        return $this === self::Add ? 1 : -1;
    }

    public function label(): string
    {
        return $this === self::Add ? 'adds to book' : 'reduces book';
    }
}
