<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Data;

use App\Domain\Kernel\Ledger\ChartOfAccounts;

/**
 * The VAT rate effective on a date, as a projection — D3's mirror image again, like
 * {@see ChartOfAccounts}: a door that answers with the model would let
 * the caller write through it, and the rate register is Invoicing's own data (Q4).
 *
 * `taxOn` keeps the per-line HALF_UP rounding inside the projection, so a caller cannot
 * recompute VAT its own way and file a return the ledger cannot reproduce.
 */
final class EffectiveVatRate
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        private readonly int $rateX10000,
        private readonly bool $chargesTax,
    ) {}

    public function taxOn(int $amountMinor): int
    {
        if (! $this->chargesTax || $this->rateX10000 === 0) {
            return 0;
        }

        // 12.00% is stored as 120 000 (a percentage at two decimals), so the divisor is the scale
        // times the second hundred in "per cent" — the same arithmetic the defect note records.
        $divisor = 10_000 * 100;

        return intdiv($amountMinor * $this->rateX10000 + intdiv($divisor, 2), $divisor);
    }

    /**
     * The rate as the percentage a person reads and edits it — 12.0, 12.5, 0.
     *
     * The settings screen shows this rather than the stored integer: a Owner editing "12"
     * should never have to know the register keeps a percentage at two decimals, and the round trip
     * back is the Action's `percentToScale` guard, which refuses a figure the scale cannot hold.
     */
    public function percent(): float
    {
        return $this->rateX10000 / 10_000;
    }
}
