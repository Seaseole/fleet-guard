<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fleet\Models\Truck;
use Illuminate\Support\Facades\DB;

/**
 * Edits a truck's master details (§4.3, D1).
 *
 * `odometer_km` is not editable here on purpose: it is a projection of readings, and an edit form
 * that could type over it would be the silent correction §4.3's `plausibility` column exists to
 * prevent. Mileage arrives through {@see RecordOdometerReading}; status through
 * {@see ChangeTruckStatus}.
 */
final class UpdateTruck
{
    public function __invoke(Truck $truck, TruckDto $dto): Truck
    {
        return DB::transaction(function () use ($truck, $dto): Truck {
            $truck->fill([
                'registration_no' => $dto->registrationNo,
                'fleet_no' => $dto->fleetNo,
                'vin' => $dto->vin,
                'make' => $dto->make,
                'model' => $dto->model,
                'year' => $dto->year,
                'capacity_tonnes_10_2' => $dto->capacityTonnes,
                'engine_hours_10_2' => $dto->engineHours,
                'purchase_amount_base' => $dto->purchaseAmountBase,
                'purchased_on' => $dto->purchasedOn,
                'tyre_size' => $dto->tyreSize,
                'service_interval_km' => $dto->serviceIntervalKm,
                'service_interval_hours' => $dto->serviceIntervalHours,
                'service_interval_months' => $dto->serviceIntervalMonths,
                'fuel_type' => $dto->fuelType,
                'main_tank_litres' => $dto->mainTankLitres,
                'aux_tank_litres' => $dto->auxTankLitres,
                'expected_consumption_per_100' => $dto->expectedConsumptionPer100,
                'compliance_flags' => $dto->complianceFlags,
                'active' => $dto->active,
            ]);

            $truck->save();

            return $truck;
        });
    }
}
