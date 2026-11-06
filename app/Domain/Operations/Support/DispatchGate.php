<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Domain\Fleet\Queries\FleetDirectory;
use App\Domain\Kernel\Documents\DocumentType;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\TripStatus;
use App\Domain\People\Queries\DriverDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The `planned → assigned` validity check, in one place.
 *
 * This is the gate the phase's definition of done turns on: **dispatch refuses an expired licence**.
 * Four things are asked, all of them as of the trip's planned departure rather than as of now,
 * because "valid today" is worthless for a truck that leaves on Friday — a licence that lapses
 * mid-corridor is discovered at a border, not in an office.
 *
 * 1. the truck (and trailer, if named) is available and still on the books;
 * 2. the driver's status and **both** papers cover the start date;
 * 3. a *filed* document — the insurance certificate, the scanned licence — has not expired;
 * 4. the driver is not already committed to another trip at the same time.
 *
 * Overlap for the truck itself is not this class's job: Fleet's `AssignVehicle` refuses it while
 * holding a row lock on the assignment, and a second copy of that rule here would be a rule that can
 * drift. What this gate adds is the answers the dispatcher can act on, collected in one pass so the
 * screen lists every problem instead of one per click.
 *
 * One trip is one question; a week of trips is one round of reads. {@see findingsFor()} answers a
 * whole board with a fixed number of queries, and `findings()` is that same answer for a single row
 * — two entry points, one rule set, so the board and the dispatch refusal cannot disagree.
 */
final class DispatchGate
{
    public function __construct(
        private readonly FleetDirectory $fleet,
        private readonly DriverDirectory $drivers,
    ) {}

    /**
     * @return list<string> empty when the trip may be dispatched
     */
    public function findings(Trip $trip): array
    {
        return $this->findingsFor([$trip])[$trip->id] ?? [];
    }

    public function mayDispatch(Trip $trip): bool
    {
        return $this->findings($trip) === [];
    }

    /**
     * The findings for every trip in the set, keyed by trip id.
     *
     * The board used to ask this once per row, and each answer cost a truck, a trailer, a driver,
     * two document reads and a clash read. The same questions are asked here with a bulk read per
     * kind: every truck, every trailer, every driver, every possibly-expired paper and every
     * same-day commit, each fetched once for the whole set.
     *
     * @param  iterable<Trip>  $trips
     * @return array<string, list<string>>
     */
    public function findingsFor(iterable $trips): array
    {
        /** @var Collection<int, Trip> $rows */
        $rows = Collection::make($trips)->values();

        if ($rows->isEmpty()) {
            return [];
        }

        /** @var array<int, CarbonImmutable> $on the day each trip is judged against */
        $on = [];

        foreach ($rows as $trip) {
            $on[$trip->id] = $this->startDate($trip);
        }

        /** @var non-empty-list<string> $days */
        $days = array_values(array_map(static fn (CarbonImmutable $day): string => $day->toDateString(), $on));
        $first = min($days);
        $last = max($days);

        $trucks = $this->fleet->trucksById($rows->pluck('truck_id')->filter()->all());
        $trailers = $this->fleet->trailersById($rows->pluck('trailer_id')->filter()->all());
        $drivers = $this->drivers->findMany($rows->pluck('driver_id')->filter()->all());
        $papers = $this->filedPapers($rows, $last);
        $clashes = $this->sameDayCommits($rows, $first, $last);

        $found = [];

        foreach ($rows as $trip) {
            $day = $on[$trip->id];
            $findings = [];

            $truck = $trucks[$trip->truck_id] ?? null;

            if ($truck === null) {
                $findings[] = sprintf('Truck #%d is not in this company\'s fleet.', $trip->truck_id);
            } elseif (! $truck->mayBeDispatched()) {
                $findings[] = sprintf('Truck %s is %s, so it cannot be dispatched.', $truck->label, $truck->status->label());
            }

            if ($trip->trailer_id !== null) {
                $trailer = $trailers[$trip->trailer_id] ?? null;

                if ($trailer === null) {
                    $findings[] = sprintf('Trailer #%d is not in this company\'s fleet.', $trip->trailer_id);
                } elseif (! $trailer->mayBeDispatched()) {
                    $findings[] = sprintf('Trailer %s is %s, so it cannot be hauled.', $trailer->label, $trailer->status->label());
                }
            }

            $driver = $drivers[$trip->driver_id] ?? null;

            if ($driver === null) {
                $findings[] = sprintf('Driver #%d is not in this company.', $trip->driver_id);
            } elseif (! $driver->eligibleOn($day)) {
                $findings[] = sprintf(
                    '%s cannot drive on %s: status %s, licence %s, PRDP permit %s.',
                    $driver->name,
                    $day->toDateString(),
                    $driver->status->value,
                    $driver->licenceExpiresOn === null ? 'not on file' : $driver->licenceExpiresOn->toDateString(),
                    $driver->permitExpiresOn === null ? 'not on file' : $driver->permitExpiresOn->toDateString(),
                );
            }

            foreach ($papers as $key => $documents) {
                // Only this trip's subjects; the bulk read holds every subject in the set.
                if ($key !== 'truck:'.$trip->truck_id && $key !== 'driver:'.$trip->driver_id) {
                    continue;
                }

                foreach ($documents as $document) {
                    if ($document->expires_on === null || $document->expires_on->toDateString() >= $day->toDateString()) {
                        continue;
                    }

                    $findings[] = sprintf(
                        'The filed %s "%s" against the %s expired on %s, before this trip departs.',
                        $document->type instanceof DocumentType ? $document->type->value : (string) $document->type,
                        $document->title,
                        $document->morph_type,
                        $document->expires_on->toDateString(),
                    );
                }
            }

            foreach ($clashes[$trip->driver_id.'|'.$day->toDateString()] ?? [] as $other) {
                if ($other->id === $trip->id) {
                    continue;
                }

                $findings[] = sprintf(
                    'That driver is already on trip %s, planned for the same day.',
                    $other->trip_no,
                );
            }

            $found[$trip->id] = $findings;
        }

        return $found;
    }

    /**
     * Filed documents that have lapsed before the latest day in the set, grouped by subject.
     *
     * Only `current` paper is asked: a superseded scan is evidence of what was once filed, not a
     * claim about today. Each trip then keeps the papers against its own subject that
     * expired before *its* start date — the bulk read is per set, the comparison is per trip.
     *
     * @param  Collection<int, Trip>  $trips
     * @return array<string, list<Document>> keyed `morphType:morphId`
     */
    private function filedPapers(Collection $trips, string $latestDay): array
    {
        $truckIds = $trips->pluck('truck_id')->filter()->unique()->values()->all();
        $driverIds = $trips->pluck('driver_id')->filter()->unique()->values()->all();

        if ($truckIds === [] && $driverIds === []) {
            return [];
        }

        /** @var Collection<int, Document> $documents */
        $documents = Document::query()
            ->where('lifecycle', Lifecycle::Current->value)
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<', $latestDay)
            ->where(function ($query) use ($truckIds, $driverIds): void {
                if ($truckIds !== []) {
                    $query->orWhere(fn ($truck) => $truck
                        ->where('morph_type', 'truck')
                        ->whereIn('morph_id', $truckIds)
                        ->where('type', DocumentType::Insurance->value));
                }

                if ($driverIds !== []) {
                    $query->orWhere(fn ($driver) => $driver
                        ->where('morph_type', 'driver')
                        ->whereIn('morph_id', $driverIds)
                        ->whereIn('type', [DocumentType::Licence->value, DocumentType::Permit->value]));
                }
            })
            ->get();

        $grouped = [];

        foreach ($documents as $document) {
            $grouped[$document->morph_type.':'.$document->morph_id][] = $document;
        }

        return $grouped;
    }

    /**
     * Live trips by driver and stored day, for every driver in the set.
     *
     * Checked against trips rather than assignments because the crew is named on both sides, and it
     * is the trip the dispatcher is looking at. A trip that has reached `completed` no longer needs
     * the driver, and an aborted or cancelled one never did.
     *
     * @param  Collection<int, Trip>  $trips
     * @return array<string, list<Trip>> keyed `driverId|Y-m-d` of the stored departure date
     */
    private function sameDayCommits(Collection $trips, string $firstDay, string $lastDay): array
    {
        $driverIds = $trips->pluck('driver_id')->filter()->unique()->values()->all();

        if ($driverIds === []) {
            return [];
        }

        /** @var Collection<int, Trip> $others */
        $others = Trip::query()
            ->whereIn('driver_id', $driverIds)
            ->whereNotNull('planned_departure_at')
            ->whereDate('planned_departure_at', '>=', $firstDay)
            ->whereDate('planned_departure_at', '<=', $lastDay)
            ->whereNotIn('status', [
                TripStatus::Cancelled->value,
                TripStatus::Aborted->value,
                TripStatus::Completed->value,
                TripStatus::Closed->value,
                TripStatus::Draft->value,
            ])
            ->get(['id', 'driver_id', 'trip_no', 'status', 'planned_departure_at']);

        $grouped = [];

        foreach ($others as $other) {
            // The stored date, not the company's day: this is the comparison the single-trip query
            // has always made (`whereDate(...) = $on`), and the board must not drift from the gate.
            $grouped[$other->driver_id.'|'.$other->planned_departure_at->toDateString()][] = $other;
        }

        return $grouped;
    }

    /** The trip's own start date, in the company's day, defaulting to today for an undated plan. */
    private function startDate(Trip $trip): CarbonImmutable
    {
        $planned = $trip->planned_departure_at;

        $moment = $planned === null
            ? CarbonImmutable::now($this->companyTimezone())
            : CarbonImmutable::instance($planned)->setTimezone($this->companyTimezone());

        return $moment->startOfDay();
    }

    private function companyTimezone(): string
    {
        return CurrentCompany::timezone();
    }
}
