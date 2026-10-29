<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Data\AssignmentDto;
use App\Domain\Fleet\Exceptions\AssignmentRejected;
use App\Domain\Fleet\Models\Trailer;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Fleet\Models\VehicleAssignment;
use App\Domain\People\Queries\DriverDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Assigns a truck (and optionally a trailer and a driver) to a period (§4.3, D1).
 *
 * Three refusals, all before anything is written:
 *
 * - an asset that is not `available` — §10.1 says only an available asset may start an assignment;
 * - an overlapping assignment for the truck, trailer or driver — history is append-only, so the
 *   overlap is refused rather than the old row being closed implicitly;
 * - a driver whose status or papers do not cover the start date. People answers that question
 *   through {@see DriverDirectory}, which is why Fleet never imports the `Driver` model (D5).
 *
 * The assignment does not move the truck to `on_trip`: assignment is "who has it", departure is the
 * Phase 2 trip state, and conflating them would leave a truck on trip with no trip.
 */
final class AssignVehicle
{
    public function __construct(private readonly DriverDirectory $drivers) {}

    public function __invoke(AssignmentDto $dto): VehicleAssignment
    {
        return DB::transaction(function () use ($dto): VehicleAssignment {
            $from = CarbonImmutable::parse($dto->fromAt);

            $truck = Truck::query()->findOrFail($dto->truckId);

            if (! $truck->status->mayBeDispatched()) {
                throw AssignmentRejected::vehicleNotAvailable('Truck', $truck->label(), $truck->status->label());
            }

            $this->refuseOverlap('Truck', $truck->label(), 'truck_id', $truck->id, $from);

            $trailer = null;

            if ($dto->trailerId !== null) {
                $trailer = Trailer::query()->findOrFail($dto->trailerId);

                if (! $trailer->status->mayBeDispatched()) {
                    throw AssignmentRejected::vehicleNotAvailable('Trailer', $trailer->label(), $trailer->status->label());
                }

                $this->refuseOverlap('Trailer', $trailer->label(), 'trailer_id', $trailer->id, $from);
            }

            if ($dto->driverId !== null) {
                $driver = $this->drivers->find($dto->driverId);

                if ($driver === null) {
                    throw AssignmentRejected::driverMissing($dto->driverId);
                }

                if (! $driver->eligibleOn($from)) {
                    throw AssignmentRejected::driverNotEligible($driver->name, $from->toDateString());
                }

                $open = VehicleAssignment::query()
                    ->where('driver_id', $driver->id)
                    ->where($this->covers($from))
                    ->orderBy('from_at')
                    ->first();

                if ($open !== null) {
                    throw AssignmentRejected::driverAlreadyAssigned(
                        $driver->name,
                        $open->from_at->toDateTimeString(),
                        $open->to_at?->toDateTimeString(),
                    );
                }
            }

            return VehicleAssignment::create([
                'truck_id' => $truck->id,
                'trailer_id' => $trailer?->id,
                'driver_id' => $dto->driverId,
                'trip_id' => $dto->tripId,
                'from_at' => $from,
                'reason' => $dto->reason,
                'assigned_by' => Auth::id(),
            ]);
        });
    }

    /**
     * The new assignment is open-ended, so any existing row that is still open — or closes at or
     * after the new start — covers the same interval.
     *
     * One row shape is excluded: a window whose end equals its start. §10.2 lets a hold lift a
     * reservation *before the truck ever left*, and the honest end time for that is the moment it
     * opened — a zero-length window that still blocked the corridor would strand a truck that was
     * never actually held. Every real window keeps the inclusive rule, so a handover at 10:00 still
     * counts against a 10:00 start (§10.1's "history is append-only, so the overlap is refused rather
     * than the old row being closed implicitly").
     */
    private function refuseOverlap(string $kind, string $label, string $column, int $id, CarbonImmutable $from): void
    {
        $existing = VehicleAssignment::query()
            ->where($column, $id)
            ->where($this->covers($from))
            ->orderBy('from_at')
            ->first();

        if ($existing !== null) {
            throw AssignmentRejected::alreadyAssigned(
                $kind,
                $label,
                $existing->from_at->toDateTimeString(),
                $existing->to_at?->toDateTimeString(),
            );
        }
    }

    /** The clause that decides whether an existing row still occupies the requested instant. */
    private function covers(CarbonImmutable $from): \Closure
    {
        return static function ($query) use ($from): void {
            $query->whereNull('to_at')
                ->orWhere(static fn ($closed) => $closed->where('to_at', '>=', $from)
                    ->whereColumn('to_at', '!=', 'from_at'));
        };
    }
}
