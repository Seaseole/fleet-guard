<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Actions;

use App\Domain\Fleet\Models\Truck;
use App\Domain\Fuel\FuelTankKind;
use App\Domain\Fuel\Models\FuelTank;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Fits a tank to a truck — the row an issue fills (FUEL_TANK).
 *
 * The truck's `main_tank_litres`/`aux_tank_litres` from Phase 1 are the stated capacity, and this
 * Action seeds the two rows from them when no capacity is given, so the common case is "fit the
 * truck's stated tanks" rather than retyping numbers that are already master data. A stated capacity
 * that disagrees is named in the refusal instead of silently overwritten: the truck's figure is what
 * the cutover sheet said, and changing it is a fleet edit, not a fuel one.
 */
final class CreateFuelTank
{
    public function __invoke(int $truckId, FuelTankKind $kind, ?int $capacityLitres = null): FuelTank
    {
        if (! Gate::allows('fuel.record')) {
            throw new InvalidArgumentException('Fitting a tank is workshop recording.');
        }

        $truck = Truck::query()->find($truckId)
            ?? throw new InvalidArgumentException('That truck is not in this company\'s fleet.');

        $capacity = $capacityLitres ?? match ($kind) {
            FuelTankKind::Main => $truck->main_tank_litres,
            FuelTankKind::Auxiliary => $truck->aux_tank_litres,
        };

        if ($capacity === null || $capacity <= 0) {
            throw new InvalidArgumentException(sprintf(
                '%s states no %s capacity, so the action cannot take it from the truck. Give the capacity.',
                $truck->label(),
                mb_strtolower($kind->label()),
            ));
        }

        if (FuelTank::query()->where('truck_id', $truck->id)->where('kind', $kind)->exists()) {
            throw new InvalidArgumentException(sprintf('%s already has a %s fitted.', $truck->label(), mb_strtolower($kind->label())));
        }

        return FuelTank::create([
            'truck_id' => $truck->id,
            'kind' => $kind,
            'capacity_litres' => $capacity,
            'active' => true,
        ]);
    }
}
