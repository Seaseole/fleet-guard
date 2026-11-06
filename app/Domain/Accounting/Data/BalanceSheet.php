<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

/**
 * The balance sheet at an as-at date: cumulative posted movement on the accounts whose
 * statement axis is balance-sheet, assets against liabilities and equity, everything in
 * base minor units frozen at write time.
 *
 * The ledger has not yet *rolled* the P&L into equity — that is what closing a period does
 * — so the sheet carries the accumulated earnings of every open window as its own
 * line inside the equity side. Without it assets cannot equal liabilities + equity before the
 * first close, and a sheet that silently omits the difference is a sheet nobody can sign.
 */
final class BalanceSheet
{
    /**
     * @param  list<BalanceSheetSection>  $assetSections
     * @param  list<BalanceSheetSection>  $liabilitySections
     * @param  list<BalanceSheetSection>  $equitySections  includes the accumulated-earnings section
     */
    public function __construct(
        public readonly string $asAt,
        public readonly string $currencyCode,
        public readonly array $assetSections,
        public readonly array $liabilitySections,
        public readonly array $equitySections,
        public readonly int $accumulatedEarningsMinor,
    ) {}

    public function totalAssetsMinor(): int
    {
        return $this->sectionsTotal($this->assetSections);
    }

    public function totalLiabilitiesMinor(): int
    {
        return $this->sectionsTotal($this->liabilitySections);
    }

    public function totalEquityMinor(): int
    {
        return $this->sectionsTotal($this->equitySections);
    }

    /** The equation an accountant checks first: assets = liabilities + equity (earnings included). */
    public function differenceMinor(): int
    {
        return $this->totalAssetsMinor() - $this->totalLiabilitiesMinor() - $this->totalEquityMinor();
    }

    public function isBalanced(): bool
    {
        return $this->differenceMinor() === 0;
    }

    /** @param list<BalanceSheetSection> $sections */
    private function sectionsTotal(array $sections): int
    {
        return array_sum(array_map(static fn (BalanceSheetSection $s): int => $s->totalMinor(), $sections));
    }
}
