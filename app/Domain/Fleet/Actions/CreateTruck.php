<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Data\TruckDto;
use App\Domain\Fleet\Models\Truck;
use Illuminate\Support\Facades\DB;

/**
 * Adds a truck to the fleet (§4.3, D1).
 *
 * The initial state is assigned, not filled: `status` is not fillable, so a form cannot post its way
 * into a state the §10.1 table would have refused.
 */
final class CreateTruck
{
    public function __invoke(TruckDto $dto): Truck
    {
        return DB::transaction(function () use ($dto): Truck {
            $truck = new Truck([
                'registration_no' => $dto->registrationNo,
                'fleet_no' => $dto->fleetNo,
                'vin' => $dto->vin,
                'make' => $dto->make,
                'model' => $dto->model,
                'year' => $dto->year,
                'capacity_tonnes_10_2' => $dto->capacityTonnes,
                'odometer_km' => $dto->odometerKm,
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

            $truck->status = $dto->initialStatus;

            $truck->save();

            return $truck;
        });
    }
}
