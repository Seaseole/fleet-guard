<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

/**
 * One week of the cash forecast (Phase 5's 13-week roll-up): what is expected in, what is
 * expected out, the net, and the running cash the week is predicted to end on. Figures are base
 * minor units; an empty week is a zero, not a missing row — a gap-free calendar is what lets a
 * reader see the hole where a payment should have been.
 */
final class CashForecastLine
{
    /** @param list<string> $inKeys short labels of what lands this week */
    public function __construct(
        public readonly string $weekStart,
        public readonly string $weekEnd,
        public readonly int $expectedInMinor,
        public readonly int $expectedOutMinor,
        public readonly int $closingCashMinor,
        public readonly array $inKeys = [],
    ) {}

    public function netMinor(): int
    {
        return $this->expectedInMinor - $this->expectedOutMinor;
    }
}
