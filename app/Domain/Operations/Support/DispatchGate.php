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

/**
 * §10.2's `planned → assigned` validity check, in one place.
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
 * @return list<string>
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
        $on = $this->startDate($trip);

        $findings = [];

        $truck = $this->fleet->truck($trip->truck_id);

        if ($truck === null) {
            $findings[] = sprintf('Truck #%d is not in this company\'s fleet.', $trip->truck_id);
        } elseif (! $truck->mayBeDispatched()) {
            $findings[] = sprintf('Truck %s is %s, so it cannot be dispatched.', $truck->label, $truck->status->label());
        }

        if ($trip->trailer_id !== null) {
            $trailer = $this->fleet->trailer($trip->trailer_id);

            if ($trailer === null) {
                $findings[] = sprintf('Trailer #%d is not in this company\'s fleet.', $trip->trailer_id);
            } elseif (! $trailer->mayBeDispatched()) {
                $findings[] = sprintf('Trailer %s is %s, so it cannot be hauled.', $trailer->label, $trailer->status->label());
            }
        }

        $driver = $this->drivers->find($trip->driver_id);

        if ($driver === null) {
            $findings[] = sprintf('Driver #%d is not in this company.', $trip->driver_id);
        } elseif (! $driver->eligibleOn($on)) {
            $findings[] = sprintf(
                '%s cannot drive on %s: status %s, licence %s, PDP permit %s.',
                $driver->name,
                $on->toDateString(),
                $driver->status->value,
                $driver->licenceExpiresOn === null ? 'not on file' : $driver->licenceExpiresOn->toDateString(),
                $driver->permitExpiresOn === null ? 'not on file' : $driver->permitExpiresOn->toDateString(),
            );
        }

        foreach ($this->expiredPapers($trip, $on) as $paper) {
            $findings[] = $paper;
        }

        foreach ($this->driverDoubleBooking($trip, $on) as $clash) {
            $findings[] = $clash;
        }

        return $findings;
    }

    public function mayDispatch(Trip $trip): bool
    {
        return $this->findings($trip) === [];
    }

    /**
     * The filed papers that no longer cover the start date.
     *
     * The driver's *row* carries the two dates dispatch reads (§4.3), and the document register
     * carries the scans with their own expiry — a lapsed insurance certificate is the one that stops
     * a truck at a border, and nothing else in the schema knows about it. Only `current` paper is
     * asked: a superseded scan is evidence of what was once filed, not a claim about today (§10.8).
     *
     * @return list<string>
     */
    private function expiredPapers(Trip $trip, CarbonImmutable $on): array
    {
        $subjects = [
            'truck' => [$trip->truck_id, DocumentType::Insurance],
            'driver' => [$trip->driver_id, DocumentType::Licence, DocumentType::Permit],
        ];

        $expired = [];

        foreach ($subjects as $morphType => $spec) {
            $morphId = (string) $spec[0];
            $types = array_slice($spec, 1);

            /** @var list<Document> $documents */
            $documents = Document::query()
                ->where('morph_type', $morphType)
                ->where('morph_id', $morphId)
                ->where('lifecycle', Lifecycle::Current->value)
                ->whereIn('type', array_map(static fn (DocumentType $t): string => $t->value, $types))
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<', $on->toDateString())
                ->get()
                ->all();

            foreach ($documents as $document) {
                $expired[] = sprintf(
                    'The filed %s "%s" against the %s expired on %s, before this trip departs.',
                    $document->type instanceof DocumentType ? $document->type->value : (string) $document->type,
                    $document->title,
                    $morphType,
                    $document->expires_on?->toDateString() ?? 'an unknown date',
                );
            }
        }

        return $expired;
    }

    /**
     * A driver committed to another trip that is still live on this date.
     *
     * Checked against trips rather than assignments because the crew is named on both sides, and it
     * is the trip the dispatcher is looking at. A trip that has reached `completed` no longer needs
     * the driver, and an aborted or cancelled one never did.
     *
     * @return list<string>
     */
    private function driverDoubleBooking(Trip $trip, CarbonImmutable $on): array
    {
        $clashing = Trip::query()
            ->whereKeyNot($trip->id)
            ->where('driver_id', $trip->driver_id)
            ->whereNotNull('planned_departure_at')
            ->whereDate('planned_departure_at', '=', $on->toDateString())
            ->get()
            ->reject(static fn (Trip $other): bool => in_array($other->status, [
                TripStatus::Cancelled,
                TripStatus::Aborted,
                TripStatus::Completed,
                TripStatus::Closed,
                TripStatus::Draft,
            ], true));

        return $clashing
            ->map(static fn (Trip $other): string => sprintf(
                'That driver is already on trip %s, planned for the same day.',
                $other->trip_no,
            ))
            ->values()
            ->all();
    }

    /** The trip's own start date, in the company's day (§2), defaulting to today for an undated plan. */
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
