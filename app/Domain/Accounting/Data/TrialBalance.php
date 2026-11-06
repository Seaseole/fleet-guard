<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

use App\Domain\Kernel\Ledger\LedgerService;

/**
 * The trial balance: every account the posted ledger moved through the as-at date, and
 * the two column totals that must agree because every entry was balanced at the door
 * ({@see LedgerService}).
 *
 * An unbalanced answer here is not a rounding dispute — it is the journal claiming something the
 * entry rows deny, which is exactly what `ledger:verify` exists to catch. The screen says so in
 * the same words as the command rather than printing two confident numbers anyway.
 */
final class TrialBalance
{
    /**
     * @param  list<TrialBalanceLine>  $lines
     */
    public function __construct(
        public readonly string $asAt,
        public readonly string $currencyCode,
        public readonly array $lines,
    ) {}

    public function totalDebitMinor(): int
    {
        return array_sum(array_map(static fn (TrialBalanceLine $line): int => $line->debitMinor, $this->lines));
    }

    public function totalCreditMinor(): int
    {
        return array_sum(array_map(static fn (TrialBalanceLine $line): int => $line->creditMinor, $this->lines));
    }

    public function isBalanced(): bool
    {
        return $this->totalDebitMinor() === $this->totalCreditMinor();
    }
}
