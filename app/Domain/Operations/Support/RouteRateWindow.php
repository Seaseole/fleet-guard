<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Domain\Operations\Exceptions\RouteRateOverlap;
use App\Domain\Operations\Models\RouteRate;
use App\Domain\Operations\RouteRateType;
use Carbon\CarbonImmutable;

/**
 * The one rule MySQL cannot state: two cards for the same route, customer and basis may not be
 * live at the same moment.
 *
 * Expressed over date windows rather than as a unique key, because "does not intersect" is a range
 * comparison. A unique key on `(route, customer, rate_type, valid_from)` catches a double entry on
 * the same day and nothing else — two cards starting a month apart with no end dates are exactly
 * the pair that would make a resolver return whichever row the database felt like.
 *
 * The comparison is inclusive at both ends: a card ending 31 March and the next starting 1 April do
 * not overlap, but one ending 1 April and another starting 1 April do, and that is deliberate —
 * a single day with two prices is a wrong invoice waiting for the trip that leaves that morning.
 */
final class RouteRateWindow
{
    public static function assertFree(
        int $routeId,
        int $customerId,
        RouteRateType $type,
        string $from,
        ?string $to,
        ?int $ignoreId = null,
    ): void {
        $candidates = RouteRate::query()
            ->where('route_id', $routeId)
            ->where('customer_id', $customerId)
            ->where('rate_type', $type->value)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get();

        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = $to === null ? null : CarbonImmutable::parse($to)->startOfDay();

        foreach ($candidates as $existing) {
            if (self::overlaps($from, $to, $existing->valid_from->toDateString(), $existing->valid_to?->toDateString())) {
                $existingStart = CarbonImmutable::instance($existing->valid_from)->startOfDay();
                $existingEnd = $existing->valid_to === null
                    ? null
                    : CarbonImmutable::instance($existing->valid_to)->startOfDay();

                throw RouteRateOverlap::window(
                    $type->unit(),
                    $start->toDateString(),
                    $end?->toDateString(),
                    $existingStart->toDateString(),
                    $existingEnd?->toDateString(),
                );
            }
        }
    }

    /**
     * The comparison itself, without a database: inclusive at both ends, and a missing end means
     * "still live", so it overlaps everything that has started.
     *
     * Shared with the cutover importer, which must judge the windows earlier rows of the *same file*
     * claim before any of them is written.
     */
    public static function overlaps(string $from, ?string $to, string $otherFrom, ?string $otherTo): bool
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = $to === null ? null : CarbonImmutable::parse($to)->startOfDay();
        $otherStart = CarbonImmutable::parse($otherFrom)->startOfDay();
        $otherEnd = $otherTo === null ? null : CarbonImmutable::parse($otherTo)->startOfDay();

        $startsBeforeOtherEnds = $otherEnd === null || $start->lessThanOrEqualTo($otherEnd);
        $otherStartsBeforeEnds = $end === null || $otherStart->lessThanOrEqualTo($end);

        return $startsBeforeOtherEnds && $otherStartsBeforeEnds;
    }
}
