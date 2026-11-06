<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

/**
 * One counterparty account's cash effect over a window (the cash-flow statement): the
 * inflow is the credits the window posted against it (money that arrived), the outflow the
 * debits (money that left), both in base minor units. The net is printed alongside because
 * an account can legitimately run both ways in one window — a refund against a supplier is
 * an outflow and an inflow on the same code, and clamping either hides a fact.
 */
final class CashFlowLine
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly int $inflowMinor,
        public readonly int $outflowMinor,
    ) {}

    public function netMinor(): int
    {
        return $this->inflowMinor - $this->outflowMinor;
    }
}
