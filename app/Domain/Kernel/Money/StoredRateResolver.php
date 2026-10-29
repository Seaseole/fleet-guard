<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Money;

use Illuminate\Support\Carbon;

/**
 * Reads the rates an owner or clerk entered. This is the implementation the system ships with;
 * a feed adapter implements the same `RateResolver` contract and gets bound in a service
 * provider, which is the whole reason the interface exists (ADR-0004 #7).
 *
 * Company scoping comes from the ExchangeRate model's global scope, not from here — a resolver
 * that took a company id as an argument would invite callers to pass the wrong one.
 */
final class StoredRateResolver implements RateResolver
{
    public function resolve(string $currencyCode, Carbon $on): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('currency_code', Currency::normalise($currencyCode))
            ->where('rate_date', '<=', $on->toDateString())
            ->orderByDesc('rate_date')
            ->first();
    }

    public function isStale(string $currencyCode, Carbon $on, int $maxAgeDays = 3): bool
    {
        $rate = $this->resolve($currencyCode, $on);

        // No rate at all is the stalest possible state, but it is a different message on screen,
        // so callers that care distinguish null from stale via resolve().
        return $rate !== null && $rate->ageInDays($on) > $maxAgeDays;
    }
}
