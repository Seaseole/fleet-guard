<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Data\OdometerReadingDto;
use App\Domain\Fleet\Models\OdometerReading;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Fleet\Plausibility;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Records a measurement and moves the truck's projection forward (D1).
 *
 * Two rules make the timeline honest:
 *
 * - a reading lower than the truck's recorded odometer is stored as `suspect` unless the caller
 *   explicitly says otherwise — the measurement is kept, because the drop is the incident;
 * - the truck's `odometer_km` only ever rises. A suspect row does not drag the projection back.
 *
 * Engine hours follow the same monotonic rule; a workshop note never lowers them.
 */
final class RecordOdometerReading
{
    public function __invoke(OdometerReadingDto $dto): OdometerReading
    {
        return DB::transaction(function () use ($dto): OdometerReading {
            $truck = Truck::query()->findOrFail($dto->truckId);

            $plausibility = $dto->plausibility
                ?? ($dto->odometerKm < $truck->odometer_km ? Plausibility::Suspect : Plausibility::Ok);

            $reading = OdometerReading::create([
                'truck_id' => $truck->id,
                'trip_id' => $dto->tripId,
                'odometer_km' => $dto->odometerKm,
                'engine_hours_10_2' => $dto->engineHours,
                'read_on' => $dto->readOn,
                'source' => $dto->source,
                'plausibility' => $plausibility,
                'recorded_by' => Auth::id(),
            ]);

            $changed = false;

            if ($dto->odometerKm > $truck->odometer_km) {
                $truck->odometer_km = $dto->odometerKm;
                $changed = true;
            }

            if ($dto->engineHours !== null && $dto->engineHours > (float) ($truck->engine_hours_10_2 ?? 0)) {
                $truck->engine_hours_10_2 = $dto->engineHours;
                $changed = true;
            }

            if ($changed) {
                $truck->save();
            }

            return $reading;
        });
    }
}
