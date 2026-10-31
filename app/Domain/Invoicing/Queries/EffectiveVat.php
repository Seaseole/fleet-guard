<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Queries;

use App\Domain\Invoicing\Data\EffectiveVatRate;
use App\Domain\Invoicing\Models\TaxRate;
use App\Domain\Invoicing\TaxRateKind;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\Carbon;
use DateTimeInterface;

/**
 * Invoicing's read door onto the VAT register (§17 Q4) — the answer to "which rate applies on this
 * date?" for any module that must compute VAT on a document it is writing.
 *
 * The rate rows are Invoicing's own data: `tax_rates` exists because a fiscal document freezes one,
 * and §3.1's D5 says another module may not reach into the model to read it. Accounting's supplier
 * bills need exactly this answer (their input VAT is computed per line from the rate effective on
 * the bill date, §6.8), so the door hands back a projection rather than widening the edge.
 */
final class EffectiveVat
{
    public function on(DateTimeInterface|string $date): ?EffectiveVatRate
    {
        $rate = TaxRate::effectiveOn($date);

        if ($rate === null) {
            return null;
        }

        return new EffectiveVatRate(
            id: $rate->id,
            label: $rate->label(),
            rateX10000: $rate->rate_x10000,
            chargesTax: $rate->kind->chargesTax(),
        );
    }

    /**
     * The standard rate a document would charge today, as the percentage an Owner reads (§20.37).
     *
     * Asked of the register, not of the settings blob: the window that applies today **is** the
     * company's answer, and a second copy in the blob would be one more number that can disagree
     * with the document. An unseeded company gets `fleet.tax.standard_rate_pct` — the design's
     * §17 Q4 placeholder — so the field has a starting figure rather than an empty box.
     */
    public function standardPercent(): float
    {
        $rate = TaxRate::effectiveOn(Carbon::now(CurrentCompany::timezone())->startOfDay(), TaxRateKind::Standard);

        return $rate === null
            ? (float) config('fleet.tax.standard_rate_pct')
            : $rate->rate_x10000 / TaxRate::RATE_SCALE;
    }
}
