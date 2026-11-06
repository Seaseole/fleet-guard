<?php

declare(strict_types=1);

namespace App\Domain\Operations\Queries;

use App\Domain\Fleet\Queries\FleetDirectory;
use App\Domain\Operations\Data\TripInMotion;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use Illuminate\Support\Carbon;

/**
 * Operations' read door for "which trips are on the road" (D5).
 *
 * Telematics owns positions, not trips, and the live map has to name the trip a fix belongs to. This
 * is the same seam `FleetDirectory` set: the owning module answers with a DTO in the caller's tenant
 * scope, and the caller never imports the model.
 *
 * "On the road" is deliberately three states — departed, in transit, at a border. A loaded trip has
 * not left, an arrived one is at the customer's gate, and neither belongs on a map of trucks moving.
 * The one exception is `currentForTruck()`, which exists to attach a fix that arrives after the trip
 * has been marked arrived but before it is completed: a GPS ping is a fact about where the truck was,
 * and refusing to file it under the trip it was finishing would lose the end of the track.
 */
final class TripsInMotion
{
    /** @var list<TripStatus> */
    public const ON_THE_ROAD = [TripStatus::Departed, TripStatus::InTransit, TripStatus::AtBorder];

    /** @var list<TripStatus> */
    private const ATTACHABLE = [
        TripStatus::Departed, TripStatus::InTransit, TripStatus::AtBorder, TripStatus::Arrived,
    ];

    public function __construct(private readonly FleetDirectory $fleet) {}

    /** @return list<TripInMotion> */
    public function active(): array
    {
        return array_values(Trip::query()
            ->with('route')
            ->whereIn('status', self::ON_THE_ROAD)
            ->orderBy('departed_at')
            ->orderBy('trip_no')
            ->get()
            ->map(fn (Trip $trip): TripInMotion => $this->dto($trip))
            ->all());
    }

    /** The trip a fix should be filed under when the provider says only which truck it came from. */
    public function currentForTruck(int $truckId): ?TripInMotion
    {
        $trip = Trip::query()
            ->with('route')
            ->where('truck_id', $truckId)
            ->whereIn('status', self::ATTACHABLE)
            ->orderByDesc('departed_at')
            ->orderByDesc('created_at')
            ->first();

        return $trip === null ? null : $this->dto($trip);
    }

    /**
     * Whether the trip a provider names is really that truck's trip.
     *
     * A fix that arrives with the wrong trip on it is worse than one with no trip at all: the track
     * shows up under a journey the truck never ran, and nothing downstream would notice. Ingest asks
     * this before it believes the payload, and files the fix under the truck's own trip instead.
     */
    public function belongsToTruck(string $tripId, int $truckId): bool
    {
        return Trip::query()->where('id', $tripId)->where('truck_id', $truckId)->exists();
    }

    /** One on-road trip by ULID or trip number — what the simulator's argument names. */
    public function find(string $tripIdOrNumber): ?TripInMotion
    {
        $trip = Trip::query()
            ->with('route')
            ->whereIn('status', self::ON_THE_ROAD)
            ->where(function ($query) use ($tripIdOrNumber): void {
                $query->where('id', $tripIdOrNumber)->orWhere('trip_no', $tripIdOrNumber);
            })
            ->first();

        return $trip === null ? null : $this->dto($trip);
    }

    private function dto(Trip $trip): TripInMotion
    {
        return new TripInMotion(
            id: $trip->id,
            tripNo: $trip->trip_no,
            status: $trip->status,
            truckId: (int) $trip->truck_id,
            truckLabel: $this->fleet->truck((int) $trip->truck_id)?->label ?? '#'.$trip->truck_id,
            routeCode: $trip->route?->code,
            origin: $trip->route?->origin,
            destination: $trip->route?->destination,
            distanceKm: $trip->route === null ? null : (float) $trip->route->distance_km,
            odometerStartKm: $trip->odometer_start_km === null ? null : (int) $trip->odometer_start_km,
            departedAt: $trip->departed_at === null ? null : Carbon::instance($trip->departed_at)->toImmutable(),
        );
    }
}
