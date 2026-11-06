<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Money;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The FX block from, copied verbatim onto every transactional table (ADR-0004 #3).
 *
 * One object instead of eight loose columns being re-derived at each call site: the columns
 * only make sense together, and the invariant `base_amount == round(amount × fx_rate)` is
 * enforced here so no screen can forget it. `ledger:verify` re-checks it against the rows.
 *
 * Dates arrive as DateTimeInterface and are normalised with Carbon::instance(): the app boots
 * with `Date::use(CarbonImmutable::class)`, so a caller's `now()` is not a Carbon.
 */
final class Fx
{
    private function __construct(
        public readonly Money $foreign,
        public readonly string $rate,
        public readonly FxRateSource $source,
        public readonly Carbon $rateDate,
        public readonly ?string $ref,
        public readonly Money $base,
    ) {}

    public static function foreignToBase(
        int|string $foreignMinorUnits,
        Currency|string $foreignCurrency,
        string $rate,
        Currency|string $baseCurrency,
        FxRateSource $source,
        DateTimeInterface|CarbonImmutable $rateDate,
        ?string $ref = null,
    ): self {
        $foreign = Money::ofMinor($foreignMinorUnits, $foreignCurrency);
        $base = $foreign->toBase($rate, $baseCurrency);

        return new self($foreign, $rate, $source, Carbon::instance($rateDate), $ref, $base);
    }

    /**
     * ADR-0004 #6, edit rule 2: the base amount is what the bank actually took, so the rate
     * moves and the source becomes manual_override. Editing the foreign side just calls
     * foreignToBase() again with the stored rate — that is edit rule 1, and it is why there are
     * exactly two named ways to build one of these.
     */
    public static function withBaseAmount(
        int|string $foreignMinorUnits,
        Currency|string $foreignCurrency,
        int|string $baseMinorUnits,
        Currency|string $baseCurrency,
        DateTimeInterface|CarbonImmutable $rateDate,
        ?string $ref = null,
    ): self {
        $foreign = Money::ofMinor($foreignMinorUnits, $foreignCurrency);
        $base = Money::ofMinor($baseMinorUnits, $baseCurrency);

        return new self(
            $foreign,
            $foreign->rateToBaseImpliedBy($base),
            FxRateSource::ManualOverride,
            Carbon::instance($rateDate),
            $ref,
            $base,
        );
    }

    /**
     * The eight columns. `base_currency_code` is copied from the company at write time rather
     * than read from config at read time, which is what lets a period's numbers stay
     * readable if the company's base currency ever changes.
     *
     * @return array<string, string|int|null>
     */
    public function toColumns(): array
    {
        return [
            'currency_code' => $this->foreign->currencyCode,
            'amount' => $this->foreign->minorUnits,
            'fx_rate' => $this->rate,
            'fx_rate_source' => $this->source->value,
            'fx_rate_date' => $this->rateDate->toDateString(),
            'fx_rate_ref' => $this->ref,
            'base_currency_code' => $this->base->currencyCode,
            'base_amount' => $this->base->minorUnits,
        ];
    }

    public function baseAmount(): Money
    {
        return $this->base;
    }

    public function foreignAmount(): Money
    {
        return $this->foreign;
    }
}
