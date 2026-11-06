<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

/**
 * The whole forecast sheet: opening cash, thirteen lines, and the three
 * scheduled sources that produced them by name — so the number on the last row always has an
 * answer to "where is that from?" without a second screen.
 */
final class CashForecast
{
    /**
     * @param  list<CashForecastLine>  $lines
     * @param  list<string>  $assumptions
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $currencyCode,
        public readonly int $openingCashMinor,
        public readonly array $lines,
        public readonly array $assumptions = [],
    ) {}

    public function expectedInMinor(): int
    {
        return array_sum(array_map(static fn (CashForecastLine $l): int => $l->expectedInMinor, $this->lines));
    }

    public function expectedOutMinor(): int
    {
        return array_sum(array_map(static fn (CashForecastLine $l): int => $l->expectedOutMinor, $this->lines));
    }

    public function closingCashMinor(): int
    {
        return $this->openingCashMinor + $this->expectedInMinor() - $this->expectedOutMinor();
    }

    /** The first week the projection runs dry, if any — the number the 13-week sheet exists to find. */
    public function firstNegativeWeek(): ?CashForecastLine
    {
        foreach ($this->lines as $line) {
            if ($line->closingCashMinor < 0) {
                return $line;
            }
        }

        return null;
    }
}
