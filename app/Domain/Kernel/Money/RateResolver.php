<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Money;

use Illuminate\Support\Carbon;

/**
 * ADR-0004 #7: rates are manual first, behind this interface, so a Reserve Bank of Botswana or
 * bank-statement feed becomes an adapter rather than a refactor of every document screen.
 */
interface RateResolver
{
    /** The newest rate for this currency as of `$on`, or null when none has been entered. */
    public function resolve(string $currencyCode, Carbon $on): ?ExchangeRate;

    /**
     * True when a document opened today would be pre-filled from a rate older than the corridor
     * should tolerate. The caller decides whether that is a warning badge or a hard stop —
     * The design makes it a warning, because border posts do not always have a fresh bulletin.
     */
    public function isStale(string $currencyCode, Carbon $on, int $maxAgeDays = 3): bool;
}
