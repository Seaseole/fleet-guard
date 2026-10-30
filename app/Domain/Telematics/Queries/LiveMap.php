<?php

declare(strict_types=1);

namespace App\Domain\Telematics\Queries;

use App\Domain\Fleet\Queries\FleetDirectory;
use App\Domain\Operations\Queries\TripsInMotion;
use App\Domain\Telematics\Data\LiveVehicle;
use App\Domain\Telematics\Models\GpsPosition;
use App\Domain\Telematics\Models\LatestPosition;
use Illuminate\Support\Carbon;

/**
 * Telematics' read door: where the fleet is, and how one trip got there.
 *
 * Both reads go through the projection and the history table respectively, and neither touches another
 * module's models — the trip facts come from Operations' {@see TripsInMotion} DTO and the truck labels
 * from Fleet's {@see FleetDirectory}, the two seams §13 D5 asks for.
 *
 * `vehicles()` reads one row per truck, not one row per fix. That is the whole point of §14.3's
 * projection: "where are my trucks" stays one small query as the history table grows past a million
 * rows, and the poll that refreshes the map every minute does not get slower with it.
 */
final class LiveMap
{
    public function __construct(
        private readonly TripsInMotion $trips,
        private readonly FleetDirectory $fleet,
    ) {}

    /** @return list<LiveVehicle> */
    public function vehicles(): array
    {
        $latest = LatestPosition::query()->orderBy('truck_id')->get();

        if ($latest->isEmpty()) {
            return [];
        }

        $onRoad = [];

        foreach ($this->trips->active() as $trip) {
            $onRoad[$trip->id] = $trip;
        }

        $vehicles = [];

        foreach ($latest as $position) {
            $trip = $position->trip_id === null ? null : ($onRoad[$position->trip_id] ?? null);

            $vehicles[] = new LiveVehicle(
                truckId: (int) $position->truck_id,
                truckLabel: $this->fleet->truck((int) $position->truck_id)?->label ?? '#'.$position->truck_id,
                trip: $trip,
                lat: (float) $position->lat_10_7,
                lng: (float) $position->lng_10_7,
                speedKph: $position->speed_kph,
                headingDeg: $position->heading_deg,
                odometerKm: $position->odometer_km,
                accuracyM: $position->accuracy_m,
                fixedAt: Carbon::instance($position->fixed_at)->toImmutable(),
                provider: (string) $position->provider,
            );
        }

        return $vehicles;
    }

    /**
     * One trip's track, oldest first — the polyline the map draws and the table under it.
     *
     * Capped because a week-long corridor at a 30 s ping is 20 k fixes, and a browser that draws all
     * of them is not a map, it is a hang. The cap keeps the shape of the journey; Phase 7's playback
     * will page through the rest.
     *
     * @return list<GpsPosition>
     */
    public function track(string $tripId, int $limit = 200): array
    {
        return GpsPosition::query()
            ->where('trip_id', $tripId)
            ->orderByDesc('fixed_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->reverse()
            ->values()
            ->all();
    }

    /** How many fixes are on file for a trip — the number the track table's footer prints. */
    public function trackSize(string $tripId): int
    {
        return GpsPosition::query()->where('trip_id', $tripId)->count();
    }
}
