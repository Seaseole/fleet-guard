<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Maintenance\Models\Tyre;
use App\Domain\Maintenance\Models\TyrePosition;
use App\Domain\Maintenance\TyreSlot;
use App\Domain\Maintenance\TyreStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Opens a fitment: one casing onto one slot of one truck (TYRE_POSITION).
 *
 * The row is the only place a tyre's whereabouts is recorded, so the three things that must be true
 * are proven here rather than left to the table: the tyre is on the spares rack, the slot is empty and
 * the odometer reading is not behind the truck's card. The unique indexes on the generated
 * `open_position`/`open_tyre_id` columns say the same thing again for a race the lock did not see —
 * the second writer fails on the row, not on a check that ran before the first one committed.
 *
 * The odometer is recorded on the fitment and does not move the truck's own reading: odometer entry is
 * its own act with its own permission, and a fitter's number is not a substitute for the card.
 */
final class FitTyre
{
    public function __invoke(
        Tyre $tyre,
        Truck $truck,
        TyreSlot $slot,
        CarbonInterface $fittedAt,
        int $odometerKm,
    ): TyrePosition {
        if (! Gate::allows('tyres.manage')) {
            throw new InvalidArgumentException('Fitting a tyre is a workshop act.');
        }

        if ($odometerKm <= 0) {
            throw new InvalidArgumentException('A fitment needs the truck’s odometer reading.');
        }

        return DB::transaction(function () use ($tyre, $truck, $slot, $fittedAt, $odometerKm): TyrePosition {
            /** @var Tyre $lockedTyre */
            $lockedTyre = Tyre::query()->whereKey($tyre->id)->lockForUpdate()->firstOrFail();

            /** @var Truck $lockedTruck */
            $lockedTruck = Truck::query()->whereKey($truck->id)->lockForUpdate()->firstOrFail();

            if (! $lockedTruck->active || $lockedTruck->status === FleetStatus::Sold) {
                throw new InvalidArgumentException(sprintf('%s has left the fleet; its tyres are taken off before it goes.', $lockedTruck->label()));
            }

            if ($lockedTyre->status !== TyreStatus::OnSpares) {
                $open = TyrePosition::query()
                    ->where('tyre_id', $lockedTyre->id)
                    ->whereNull('removed_at')
                    ->with('truck')
                    ->first();

                throw new InvalidArgumentException($open === null
                    ? sprintf('%s is %s; only a tyre on the spares rack is fitted.', $lockedTyre->label(), strtolower($lockedTyre->status->label()))
                    : sprintf('%s is still on %s (%s); remove it there first.', $lockedTyre->label(), $open->truck?->label() ?? 'a truck', $open->position->label()));
            }

            if ($odometerKm < $lockedTruck->odometer_km) {
                throw new InvalidArgumentException(sprintf(
                    '%s’s odometer card already reads %s km; a fitment at %s km would run the tyre backwards.',
                    $lockedTruck->label(),
                    number_format($lockedTruck->odometer_km),
                    number_format($odometerKm),
                ));
            }

            $occupied = TyrePosition::query()
                ->where('truck_id', $lockedTruck->id)
                ->where('position', $slot->value)
                ->whereNull('removed_at')
                ->with('tyre')
                ->first();

            if ($occupied !== null) {
                throw new InvalidArgumentException(sprintf(
                    '%s is occupied on %s by %s; remove that tyre before fitting another.',
                    $slot->label(),
                    $lockedTruck->label(),
                    $occupied->tyre?->label() ?? 'a tyre',
                ));
            }

            $retreadCount = (int) (TyrePosition::query()
                ->where('tyre_id', $lockedTyre->id)
                ->orderByDesc('fitted_at')
                ->orderByDesc('id')
                ->value('retread_count') ?? 0);

            $position = TyrePosition::create([
                'tyre_id' => $lockedTyre->id,
                'truck_id' => $lockedTruck->id,
                'position' => $slot,
                'fitted_at' => $fittedAt,
                'fitted_odometer_km' => $odometerKm,
                'retread_count' => $retreadCount,
            ]);

            $lockedTyre->forceFill(['status' => TyreStatus::InService])->save();

            return $position;
        }, 3);
    }
}
