<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Actions;

use App\Domain\Fleet\Data\TrailerDto;
use App\Domain\Fleet\Models\Trailer;
use Illuminate\Support\Facades\DB;

/**
 * Adds a trailer (D1).
 */
final class CreateTrailer
{
    public function __invoke(TrailerDto $dto): Trailer
    {
        return DB::transaction(function () use ($dto): Trailer {
            $trailer = new Trailer([
                'registration_no' => $dto->registrationNo,
                'fleet_no' => $dto->fleetNo,
                'vin' => $dto->vin,
                'type' => $dto->type,
                'capacity_tonnes_10_2' => $dto->capacityTonnes,
                'axle_config' => $dto->axleConfig,
                'odometer_km' => $dto->odometerKm,
                'active' => $dto->active,
            ]);

            $trailer->status = $dto->initialStatus;

            $trailer->save();

            return $trailer;
        });
    }
}
