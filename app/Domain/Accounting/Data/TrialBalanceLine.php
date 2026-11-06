<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

/**
 * One postable account on the trial balance: the debit and credit columns as the ledger moved
 * them, in base minor units (the statement never shows a foreign amount, because the
 * base figure is the one that ties).
 */
final class TrialBalanceLine
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly int $debitMinor,
        public readonly int $creditMinor,
    ) {}

    /** The movement signed the ledger's way: debit positive, credit negative. */
    public function netMinor(): int
    {
        return $this->debitMinor - $this->creditMinor;
    }
}
