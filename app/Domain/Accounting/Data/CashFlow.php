<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

/**
 * The cash-flow statement for one window, direct method: every posted entry that moved
 * one of the company's cash or bank accounts is attributed to its counterparties, and the
 * counterparty accounts answer "where did the money come from and what was it used for".
 *
 * The identity the reader should be able to check with a calculator — opening cash + the three
 * section nets = closing cash — is carried as {@see isConsistent()} rather than assumed,
 * because the two halves are read through different doors (statementTotals for the cash
 * balances, cashCounterpartyTotals for the movements) and a disagreement means a posting bug,
 * not a rounding habit (the tie-out principle applied inside one statement).
 */
final class CashFlow
{
    /** @param list<CashFlowSection> $sections */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $currencyCode,
        public readonly array $sections,
        public readonly int $openingCashMinor,
        public readonly int $closingCashMinor,
    ) {}

    public function netChangeMinor(): int
    {
        return array_sum(array_map(static fn (CashFlowSection $s): int => $s->netMinor(), $this->sections));
    }

    public function isConsistent(): bool
    {
        return $this->openingCashMinor + $this->netChangeMinor() === $this->closingCashMinor;
    }

    public function section(string $key): ?CashFlowSection
    {
        foreach ($this->sections as $section) {
            if ($section->key === $key) {
                return $section;
            }
        }

        return null;
    }
}
