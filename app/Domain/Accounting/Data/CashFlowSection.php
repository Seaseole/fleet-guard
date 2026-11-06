<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

/**
 * One section of the cash-flow statement — operating, investing or financing — over the window.
 */
final class CashFlowSection
{
    /** @param list<CashFlowLine> $lines */
    public function __construct(
        public readonly string $key,
        public readonly string $heading,
        public readonly array $lines,
    ) {}

    public function inflowMinor(): int
    {
        return array_sum(array_map(static fn (CashFlowLine $l): int => $l->inflowMinor, $this->lines));
    }

    public function outflowMinor(): int
    {
        return array_sum(array_map(static fn (CashFlowLine $l): int => $l->outflowMinor, $this->lines));
    }

    public function netMinor(): int
    {
        return $this->inflowMinor() - $this->outflowMinor();
    }
}
