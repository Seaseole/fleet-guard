<?php

declare(strict_types=1);

namespace App\Domain\Telematics\Data;

use App\Domain\Kernel\Settings\CompanySettings;
use App\Domain\Operations\Data\TripInMotion;
use Carbon\CarbonImmutable;

/**
 * One truck's last known place, ready for the map (§14.3's projection, joined to the trip it is on).
 *
 * The trip fields are nullable on purpose: a truck in the yard has a fix and no journey, and the map
 * shows it rather than hiding it. What the row never carries is money, crew or customer — the live map
 * is `gps.view`, and §9.3 keeps that permission away from the figures a Dispatcher may not see.
 */
final readonly class LiveVehicle
{
    public function __construct(
        public int $truckId,
        public string $truckLabel,
        public ?TripInMotion $trip,
        public float $lat,
        public float $lng,
        public ?int $speedKph,
        public ?int $headingDeg,
        public ?int $odometerKm,
        public ?int $accuracyM,
        public CarbonImmutable $fixedAt,
        public string $provider,
    ) {}

    /** How long ago the fix was taken, in whole minutes — the number the map prints. */
    public function ageMinutes(): int
    {
        return max(0, (int) $this->fixedAt->diffInMinutes(CarbonImmutable::now()));
    }

    /**
     * Whether the feed has gone quiet on this truck.
     *
     * A stale fix is shown, not hidden: "we last heard from B 123 ABC four hours ago at Kazungula" is
     * the useful sentence, and an empty map would read as "the truck is not moving".
     */
    public function isStale(): bool
    {
        $minutes = (int) app(CompanySettings::class)->get('telematics.stale_after_minutes');

        return $this->fixedAt->lessThan(CarbonImmutable::now()->subMinutes($minutes));
    }

    public function placeLabel(): string
    {
        return sprintf('%.5f, %.5f', $this->lat, $this->lng);
    }

    public function speedLabel(): string
    {
        return $this->speedKph === null ? '—' : $this->speedKph.' km/h';
    }
}
